<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Teacher;

/**
 * What every printout shares: its name, its "Printed on ... by ..." line and
 * its Audit Log entry.
 *
 * The name is "[Report] - [Person] - [Range] - [Position]", e.g.
 * "Daily Report - Ana Cruz - 2026-10-22 - Principal". Browsers suggest it as
 * the file name when a printout is saved as PDF, so characters Windows does
 * not allow in file names are removed. The position is the account's role
 * label (Account::ROLE_LABELS), the same one the sidebar shows; a printout
 * never shows the account's email. The Audit Log keeps the email instead.
 */
class Printout
{
    /**
     * Each report type and the roles that print it (POST /reports/print-log
     * logs only these).
     *
     * @var array<string, list<string>>
     */
    public const REPORTS = [
        'Daily Report' => ['superadmin', 'admin', 'principal'],
        'Weekly Roster' => ['superadmin', 'admin', 'principal'],
        'Monthly Summary Report' => ['superadmin', 'admin', 'principal'],
        'Absence Report' => ['superadmin', 'admin', 'principal'],
        'Attendance Monitoring' => ['principal'],
        'DTR' => ['superadmin', 'admin'],
        'ID Badge' => ['superadmin'],
        'ID Badges' => ['superadmin'],
        'Recovery Codes' => ['superadmin'],
    ];

    /** "Daily Report - Ana Cruz - 2026-10-22 - Principal" (empty parts are left out). */
    public static function title(Account $account, string $report, ?string $range = null, ?string $person = null): string
    {
        $parts = array_filter([$report, $person, $range, $account->roleLabel()], fn (?string $part) => trim((string) $part) !== '');
        $title = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '', implode(' - ', $parts));

        return trim(preg_replace('/[\s\x00-\x1F]+/', ' ', $title));
    }

    /**
     * A printout the browser builds: its title, and what POST
     * /reports/print-log records for it.
     *
     * @return array{title: string, report: string, range: ?string, teacher_id: ?int}
     */
    public static function job(Account $account, string $report, ?string $range = null, ?string $person = null, ?int $teacherId = null): array
    {
        return [
            'title' => self::title($account, $report, $range, $person),
            'report' => $report,
            'range' => $range,
            'teacher_id' => $teacherId,
        ];
    }

    /** "2026-10-22" for one day, "2026-10-19 to 2026-10-24" for a range. */
    public static function range(string $from, string $to): string
    {
        return $from === $to ? $from : "{$from} to {$to}";
    }

    /** "2026-10" */
    public static function month(string $anyDay): string
    {
        return date('Y-m', strtotime($anyDay));
    }

    /**
     * The "Printed on ... by ..." line's parts: "Thursday, October 22, 2026",
     * "10:26 AM" and the position.
     *
     * @return array{printDate: string, printTime: string, printedBy: string}
     */
    public static function stamp(Account $account): array
    {
        return [
            'printDate' => date('l, F j, Y'),
            'printTime' => date('g:i A'),
            'printedBy' => $account->roleLabel(),
        ];
    }

    /** Records a print in the Audit Log (actor: the account's email). */
    public static function log(string $report, ?string $range = null, ?Teacher $person = null): void
    {
        Audit::log('report.printed', $person ? 'teacher' : null, $person?->id, array_filter([
            'report' => $report,
            'range' => $range,
            'personnel' => $person?->fullname ?? 'All Personnel',
        ]));
    }
}
