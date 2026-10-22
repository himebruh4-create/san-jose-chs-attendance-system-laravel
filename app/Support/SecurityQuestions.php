<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The Super Admin's 3 security questions for Forgot Password, picked from a
 * fixed list (no free-text questions like "favorite color"). The list
 * avoids workplace facts, pets, birthplace and mother's maiden name: in a
 * school the likely guesser is a colleague or relative, and those are
 * known to them or sit in HR and bank records.
 *
 * Answers are normalized (lowercase, no accents, spaces or punctuation)
 * before hashing and when checking, so "Sto. Niño" matches "sto nino".
 */
class SecurityQuestions
{
    /** How many questions an account sets (all must be answered). */
    public const COUNT = 3;

    /** Shortest accepted answer, after normalizing. */
    public const MIN_ANSWER_LENGTH = 4;

    /** Stable key => question. Keys are stored; never rename one. */
    public const LIST = [
        'childhood_best_friend' => 'What is the full name of your childhood best friend?',
        'grade1_street' => 'What was the name of the street you lived on in Grade 1?',
        'parents_met_town' => 'In what town or city did your parents meet?',
        'grade1_teacher' => "What was your Grade 1 teacher's last name?",
        'first_hs_adviser' => 'What was the last name of your first high school adviser?',
        'family_nickname' => 'What was your childhood nickname that only your family used?',
        'oldest_cousin_middle' => 'What is the middle name of your oldest cousin?',
        'first_crush' => 'What was the first and last name of your first crush?',
        'first_concert' => 'What was the first concert or live show you attended?',
        'first_beach' => 'What was the first beach or resort you remember visiting?',
        'maternal_grandparents_town' => "In what town did your mother's parents live?",
        'first_phone_number' => 'What was the first phone number you memorized?',
    ];

    /** Lowercase, accents removed, then letters and digits only. */
    public static function normalize(string $answer): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($answer)));
    }

    /**
     * Problems with a set of questions and answers, as messages for the
     * person setting them (empty when they can be saved).
     *
     * @param  array<int, mixed>  $questionKeys
     * @param  array<int, mixed>  $answers
     * @return list<string>
     */
    public static function problems(array $questionKeys, array $answers, string $fullName, string $email): array
    {
        $keys = array_values(array_map(fn ($key) => is_string($key) ? $key : '', $questionKeys));
        $normalized = array_values(array_map(fn ($answer) => is_string($answer) ? self::normalize($answer) : '', $answers));

        if (count($keys) !== self::COUNT || count($normalized) !== self::COUNT) {
            return ['Choose '.self::COUNT.' security questions and answer each one.'];
        }

        $problems = [];

        if (array_diff($keys, array_keys(self::LIST))) {
            $problems[] = 'Choose each security question from the list.';
        } elseif (count(array_unique($keys)) !== self::COUNT) {
            $problems[] = 'Choose '.self::COUNT.' different security questions.';
        }

        if (min(array_map('strlen', $normalized)) < self::MIN_ANSWER_LENGTH) {
            $problems[] = 'Each answer must have at least '.self::MIN_ANSWER_LENGTH.' letters or numbers.';
        } elseif (count(array_unique($normalized)) !== self::COUNT) {
            $problems[] = 'Use a different answer for each question.';
        }

        if (array_intersect($normalized, self::personalWords($fullName, $email))) {
            $problems[] = 'An answer cannot be your own name or email.';
        }

        return $problems;
    }

    /**
     * Replaces the account's questions and answers (all or nothing).
     *
     * @param  list<string>  $questionKeys
     * @param  list<string>  $answers
     */
    public static function save(int $accountId, array $questionKeys, array $answers): void
    {
        DB::transaction(function () use ($accountId, $questionKeys, $answers) {
            DB::table('account_security_questions')->where('account_id', $accountId)->delete();

            foreach (array_values($questionKeys) as $i => $key) {
                DB::table('account_security_questions')->insert([
                    'account_id' => $accountId,
                    'position' => $i + 1,
                    'question_key' => $key,
                    'answer_hash' => Hash::make(self::normalize(array_values($answers)[$i])),
                ]);
            }
        });
    }

    /** True when the account has all of its questions set. */
    public static function hasAll(int $accountId): bool
    {
        return DB::table('account_security_questions')->where('account_id', $accountId)->count() === self::COUNT;
    }

    /**
     * The account's question keys, in order.
     *
     * @return list<string>
     */
    public static function keysFor(int $accountId): array
    {
        return DB::table('account_security_questions')->where('account_id', $accountId)
            ->orderBy('position')->pluck('question_key')->all();
    }

    /**
     * The account's questions as shown on Forgot Password, in order.
     *
     * @return list<string>
     */
    public static function questionsFor(int $accountId): array
    {
        return array_map(fn (string $key) => self::LIST[$key] ?? $key, self::keysFor($accountId));
    }

    /**
     * True only when every answer matches. All of them are checked even
     * after a wrong one, so nothing hints at which answer was wrong.
     *
     * @param  array<int, mixed>  $answers
     */
    public static function check(int $accountId, array $answers): bool
    {
        $hashes = DB::table('account_security_questions')->where('account_id', $accountId)
            ->orderBy('position')->pluck('answer_hash')->all();
        $answers = array_values($answers);

        if (count($hashes) !== self::COUNT) {
            return false;
        }

        $allMatch = true;

        foreach ($hashes as $i => $hash) {
            $answer = is_string($answers[$i] ?? null) ? self::normalize($answers[$i]) : '';
            $allMatch = Hash::check($answer, $hash) && $allMatch;
        }

        return $allMatch;
    }

    /** Removes the account's questions, so it must set new ones at next login. */
    public static function clear(int $accountId): void
    {
        DB::table('account_security_questions')->where('account_id', $accountId)->delete();
    }

    /**
     * The person's name, each part of it, their email and the part before
     * the @, normalized like an answer.
     *
     * @return list<string>
     */
    private static function personalWords(string $fullName, string $email): array
    {
        $words = [$fullName, $email, Str::before($email, '@'), ...preg_split('/\s+/', trim($fullName))];

        return array_values(array_filter(array_map(fn (string $word) => self::normalize($word), $words)));
    }
}
