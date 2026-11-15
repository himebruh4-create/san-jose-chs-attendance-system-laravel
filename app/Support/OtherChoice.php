<?php

namespace App\Support;

/**
 * A dropdown's "Other" choice and the text box it shows (data-other-input in
 * public/js/app-shell.js). The text is required with "Other" and ignored
 * otherwise. It is saved in the record's own column, after "Other: " by
 * default ("Other: Bereavement Leave"), so every page that shows the field
 * shows what was typed; pages escape it like any other text.
 */
class OtherChoice
{
    public const PREFIX = 'Other: ';

    public static function isOther(?string $choice): bool
    {
        return in_array(strtolower(trim((string) $choice)), ['other', 'others'], true);
    }

    /** A value saved by resolve(): "Other", or "Other: <text>". */
    public static function isOtherValue(?string $value): bool
    {
        return self::isOther($value) || str_starts_with((string) $value, self::PREFIX);
    }

    /** The typed text of a saved "Other: <text>" value ('' if none). */
    public static function text(?string $value): string
    {
        return str_starts_with((string) $value, self::PREFIX) ? substr((string) $value, strlen(self::PREFIX)) : '';
    }

    /**
     * The value to save for a dropdown, or an error when "Other" has no text.
     * $maxLength is the column's length; $prefix false saves the text alone.
     *
     * @return array{0: ?string, 1: ?string} [value, error]
     */
    public static function resolve(string $choice, ?string $text, string $what, int $maxLength, bool $prefix = true): array
    {
        $choice = trim($choice);

        if (! self::isOther($choice)) {
            return [$choice, null];
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($text === '') {
            return [null, "Please type the {$what} for Other."];
        }

        $value = ($prefix ? self::PREFIX : '').$text;

        if (mb_strlen($value) > $maxLength) {
            return [null, "The {$what} for Other is too long (".($maxLength - ($prefix ? strlen(self::PREFIX) : 0)).' characters maximum).'];
        }

        return [$value, null];
    }
}
