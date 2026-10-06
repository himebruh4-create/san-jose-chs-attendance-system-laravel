<?php

namespace App\Http\Controllers\Principal;

use App\Domain\Attendance\AttendanceData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use function App\Domain\Attendance\getTeacherDayStatus;

/**
 * Principal Attendance Monitoring (was principal/attendance-monitoring.php):
 * day-by-day status of one person, or everyone over at most 7 days.
 */
class MonitoringController extends Controller
{
    public function show(Request $request)
    {
        $selectedTeacherId = (int) $request->query('teacher_id', 0);
        $dateFrom = $this->date($request->query('date_from')) ?? date('Y-m-d', strtotime('monday this week'));
        $dateTo = $this->date($request->query('date_to')) ?? date('Y-m-d');

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $today = date('Y-m-d');
        $effectiveDateTo = $dateTo > $today ? $today : $dateTo;

        // "All Personnel" is limited to 7 days at a time.
        $daysRequested = (strtotime($effectiveDateTo) - strtotime($dateFrom)) / 86400 + 1;
        $rangeTooLarge = $selectedTeacherId === 0 && $daysRequested > 7;

        $allTeachers = DB::table('teachers')->where('is_deleted', 0)->orderBy('fullname')
            ->get(['id', 'fullname', 'id_number', 'department'])
            ->map(fn ($row) => (array) $row)
            ->all();

        $selectedTeacherName = '';
        foreach ($allTeachers as $t) {
            if ((int) $t['id'] === $selectedTeacherId) {
                $selectedTeacherName = $t['fullname'];
            }
        }

        $rows = [];

        if (! $rangeTooLarge) {
            $events = AttendanceData::activeEvents();
            $teachersToShow = $selectedTeacherId > 0
                ? array_filter($allTeachers, fn ($t) => (int) $t['id'] === $selectedTeacherId)
                : $allTeachers;

            foreach ($teachersToShow as $teacher) {
                [$leaves, $adjustments, $attendance, $absences, $schedules] = AttendanceData::preload((int) $teacher['id'], $dateFrom, $effectiveDateTo);

                for ($c = strtotime($dateFrom); $c <= strtotime($effectiveDateTo); $c = strtotime('+1 day', $c)) {
                    $date = date('Y-m-d', $c);
                    $dayOfWeek = date('N', $c);

                    // Sunday is always off; Saturday only for people scheduled on it.
                    if ($dayOfWeek == 7 || ($dayOfWeek == 6 && ! isset($schedules[strtolower(date('l', $c))]))) {
                        continue;
                    }

                    $row = $attendance[$date] ?? null;
                    $result = getTeacherDayStatus($date, $leaves, $adjustments, $events, $row, $absences, $schedules, teacherId: (int) $teacher['id']);

                    // Days this person was never scheduled to work are not shown.
                    if ($result['status'] === 'Not Scheduled') {
                        continue;
                    }

                    $adj = $adjustments[$date] ?? [];

                    $rows[] = [
                        'date' => $date,
                        'fullname' => $teacher['fullname'],
                        'department' => $teacher['department'],
                        'am_arrival' => $row['am_arrival'] ?? ($adj['am_arrival'] ?? null),
                        'am_departure' => $row['am_departure'] ?? ($adj['am_departure'] ?? null),
                        'pm_arrival' => $row['pm_arrival'] ?? ($adj['pm_arrival'] ?? null),
                        'pm_departure' => $row['pm_departure'] ?? ($adj['pm_departure'] ?? null),
                        'status' => $result['status'],
                        'detail' => $result['detail'],
                    ];
                }
            }

            // Most recent first, then by name.
            usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['fullname'], $b['fullname']));
        }

        return view('principal.monitoring', compact(
            'selectedTeacherId', 'dateFrom', 'dateTo', 'rangeTooLarge', 'allTeachers', 'selectedTeacherName', 'rows'
        ));
    }
}
