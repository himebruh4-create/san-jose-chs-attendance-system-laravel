<?php

namespace App\Domain\Attendance;

/**
 * Read-only School Schedule Changes list shown on the Admin / Principal
 * dashboards and the Super Admin personnel page. Ported from
 * dtr/schedule-changes-view.php; the markup lives in
 * resources/views/partials/schedule-changes.blade.php.
 */
class ScheduleChanges
{
    /**
     * Active changes in effect today or starting within the next $days days,
     * soonest first.
     */
    public static function inWindow(array $events, string $today, int $days = 14): array
    {
        $windowEnd = date('Y-m-d', strtotime($today.' +'.$days.' days'));
        $rows = [];

        foreach ($events as $event) {
            if (($event['status'] ?? '') !== 'Active') {
                continue;
            }

            if ($event['date_to'] >= $today && $event['date_from'] <= $windowEnd) {
                $rows[] = $event;
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['date_from'], $b['date_from']) ?: ((int) $a['id'] <=> (int) $b['id']));

        return $rows;
    }

    public static function dateLabel(array $event): string
    {
        $from = date('M j, Y', strtotime($event['date_from']));

        return $event['date_from'] === $event['date_to']
            ? $from
            : $from.' – '.date('M j, Y', strtotime($event['date_to']));
    }

    public static function durationLabel(array $event): string
    {
        if (($event['duration'] ?? 'Whole Day') !== 'Half Day') {
            return 'Whole Day';
        }

        if (! empty($event['start_time']) && ! empty($event['end_time'])) {
            return 'Half Day ('.date('g:i A', strtotime($event['start_time'])).' – '.date('g:i A', strtotime($event['end_time'])).')';
        }

        return 'Half Day';
    }

    /**
     * Rows ready for the partial: each event plus in_effect and the sorted
     * names of the personnel scheduled to work.
     *
     * @param  iterable<array{id: int, fullname: string}>  $teachers
     */
    public static function forDisplay(array $events, string $today, iterable $teachers): array
    {
        $names = [];
        foreach ($teachers as $t) {
            $t = (array) $t;
            $names[(int) $t['id']] = $t['fullname'];
        }

        return array_map(function ($event) use ($names, $today) {
            $workers = [];
            foreach (($event['worker_ids'] ?? []) as $id) {
                if (isset($names[(int) $id])) {
                    $workers[] = $names[(int) $id];
                }
            }
            sort($workers);

            return $event + [
                'in_effect' => $event['date_from'] <= $today && $event['date_to'] >= $today,
                'workers' => $workers,
                'date_label' => self::dateLabel($event),
                'duration_label' => self::durationLabel($event),
            ];
        }, self::inWindow($events, $today));
    }
}
