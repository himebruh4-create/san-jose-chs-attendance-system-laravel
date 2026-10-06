<?php

// ==========================================================
// ATTENDANCE RULES — the pure rule functions of the native-PHP system,
// copied verbatim from dtr/attendance-rules.php and dtr/dtr-core.php
// (only the namespace is new). The four functions that touched the
// database live in classes now:
//
//   dtrLoadBreakConfig()   -> BreakConfig::load()
//   dtrLoadActiveEvents()  -> AttendanceData::activeEvents()
//   dtrLogRejectedScan()   -> AttendanceData::logRejectedScan()
//   getTeacherRecords()    -> AttendanceData::teacherRecords()
//
// tests/Feature/LegacyParityTest.php runs these functions side by side
// with the originals on the real data; keep them identical unless a rule
// is deliberately changed (and then change public/js/dtr-view.js, the
// JavaScript twin, too).
// ==========================================================

namespace App\Domain\Attendance;

// ==========================================================
// ATTENDANCE RULES — single PHP source of truth (functions only).
//
//   PERSONNEL -> ASSIGNED SCHEDULE -> CONFIGURED BREAK ->
//   SCHEDULE-AWARE SCAN ROUTING -> REAL ATTENDANCE ->
//   EFFECTIVE ATTENDANCE (real scans + Super Admin adjustment fields) ->
//   HOURS / STATUS / DTR / REPORTS / PENDING REVIEW
//
// Real scans are the only source of attendance. The schedule is used
// for lateness, early departure, the overtime boundary and review
// logic — it never becomes a stored (or displayed-as-real) scan.
//
// dtr/dtr-view.php (dtrRules* functions) is the JavaScript twin of
// dtrDayCalc(); change both together (tests/differential test).
// ==========================================================

const DTR_RULES_EFFECTIVE_DATE = '2026-09-27';
const DTR_EARLY_DEPARTURE_SECONDS = 1800;    // 30 minutes
const DTR_DUPLICATE_SCAN_SECONDS  = 1;       // scan-debounce window
const DTR_REPEAT_GUARD_SECONDS    = 300;     // accidental-repeat guard: a scan less than 5 minutes after the last accepted scan of open attendance is a repeat

// OVERTIME TOLERANCE (explicit business setting). Overtime is flagged when the
// real OUT is MORE THAN this many seconds after the scheduled end. 0 = strict:
// any OUT later than the scheduled end is potential overtime. Credited hours
// are always capped at the scheduled end until a Super Admin adjustment supplies
// the OUT. JS twin: RULES_OVERTIME_TOLERANCE_SECONDS in dtr/dtr-view.php.
const DTR_OVERTIME_TOLERANCE_SECONDS = 0;

// Lunch scanning (schedules using the shared default School Lunch Break, i.e.
// regular teachers): a scan from this many seconds BEFORE the break start until
// the break end is the lunch-OUT; it is never the final OUT. 900 = 15 minutes.
const DTR_LUNCH_SCAN_EARLY_SECONDS = 900;
const DTR_OVERNIGHT_WINDOW_SECONDS = 21600;  // scans up to 6h after an overnight shift's end still belong to it

function dtrRulesApply($date) {
    return $date >= DTR_RULES_EFFECTIVE_DATE;
}

// ---------- small time helpers ----------

function dtrClockOrEmpty($value) {
    if (!is_string($value) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
        return '';
    }
    return strlen($value) === 5 ? $value . ':00' : substr($value, 0, 8);
}

function dtrSecs($value) {
    $v = dtrClockOrEmpty($value);
    if ($v === '') {
        return null;
    }
    return ((int) substr($v, 0, 2)) * 3600 + ((int) substr($v, 3, 2)) * 60 + (int) substr($v, 6, 2);
}

// ---------- configured breaks (per assigned schedule) ----------

// Entries: [time_in, time_out, break_start, break_end]. Whether a break is
// shown as "(Auto)" on the DTR / expects lunch scans is derived from HOW it
// was matched (dtrBreakForSchedule's 'source'), not stored per entry — a
// schedule with its own specific entry here is a shift/guard break (paid,
// handled internally, never shown); the shared default School Lunch Break
// is the regular-teacher lunch (see dtrBreakForSchedule / dtrIsShiftPersonnel).
function dtrDefaultBreakEntries() {
    return [
        ['time_in' => '06:00:00', 'time_out' => '14:00:00', 'break_start' => '11:00:00', 'break_end' => '12:00:00'],
        ['time_in' => '14:00:00', 'time_out' => '22:00:00', 'break_start' => '17:00:00', 'break_end' => '18:00:00'],
        ['time_in' => '22:00:00', 'time_out' => '06:00:00', 'break_start' => '02:00:00', 'break_end' => '03:00:00'],
    ];
}


// The paid break that applies to a person's assigned schedule for a day.
// Exact schedule match first; otherwise an ordinary day schedule falls
// back to the shared School Lunch Break (shown as Auto on the DTR);
// an unmatched afternoon/overnight shift has no configured break.
function dtrBreakForSchedule($cfg, $schedule) {

    if (!$schedule || empty($schedule['time_in']) || empty($schedule['time_out'])) {
        return null;
    }

    $ti = dtrClockOrEmpty($schedule['time_in']);
    $to = dtrClockOrEmpty($schedule['time_out']);

    foreach (($cfg['entries'] ?? []) as $e) {
        if ($e['time_in'] === $ti && $e['time_out'] === $to) {
            return ['start' => $e['break_start'], 'end' => $e['break_end'], 'source' => 'schedule'];
        }
    }

    if (dtrScheduleShape($schedule) === 'day' && !empty($cfg['lunch_out']) && !empty($cfg['lunch_in'])) {
        return ['start' => dtrClockOrEmpty($cfg['lunch_out']), 'end' => dtrClockOrEmpty($cfg['lunch_in']), 'source' => 'default'];
    }

    return null;
}

// Shift / guard personnel: an afternoon or overnight schedule, or a day-shape
// schedule with its OWN specifically configured break entry (e.g. 6:00 AM-2:00
// PM) rather than the shared School Lunch Break default. Decided entirely by
// schedule/break configuration (never names or IDs). JS twin:
// rulesIsShiftPersonnel in dtr/dtr-view.php.
function dtrIsShiftPersonnel($cfg, $schedule) {

    if (!$schedule) {
        return false;
    }

    if (dtrScheduleShape($schedule) !== 'day') {
        return true;
    }

    $b = dtrBreakForSchedule($cfg, $schedule);

    return $b !== null && $b['source'] === 'schedule';
}

// DTR columns that hold a shift's actual IN / OUT (display mapping only).
function dtrShiftColumns($schedule) {

    $shape = dtrScheduleShape($schedule);

    if ($shape === 'day')       return ['in' => 'am_arrival', 'out' => 'am_departure'];
    if ($shape === 'overnight') return ['in' => 'pm_arrival', 'out' => 'am_departure'];

    return ['in' => 'pm_arrival', 'out' => 'pm_departure'];
}

// ---------- effective attendance (field-level overlay) ----------

// Real scans + non-empty adjustment fields overriding the matching
// real fields. Nothing is ever filled from the schedule.
function dtrEffectiveRecord($real, $adj) {

    $eff = [];

    foreach (['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'] as $col) {
        $a = $adj ? dtrClockOrEmpty($adj[$col] ?? '') : '';
        $r = $real ? dtrClockOrEmpty($real[$col] ?? '') : '';
        $eff[$col] = $a !== '' ? $a : $r;
    }

    return $eff;
}

// First IN / final OUT of an (effective) record. IN = earliest arrival;
// OUT = PM departure, or the AM departure when the person never came
// back (nothing after it). Intermediate break columns are ignored.
function dtrInOutOf($rec, $adj = null) {

    $ins = array_values(array_filter([$rec['am_arrival'] ?? '', $rec['pm_arrival'] ?? ''], fn($v) => $v !== ''));
    sort($ins);
    $in = $ins ? $ins[0] : '';

    // A real AM departure is a LUNCH scan, never an OUT. Only an adjustment
    // that supplies an AM departure (with nothing after it) makes it the OUT.
    $out = $rec['pm_departure'] ?? '';
    if ($out === '' && ($rec['pm_arrival'] ?? '') === '' && $adj && dtrClockOrEmpty($adj['am_departure'] ?? '') !== '') {
        $out = $rec['am_departure'] ?? '';
    }

    return [$in, $out];
}

// True when the OUT comes from an adjustment (the adjustment IS the approval).
function dtrOutFromAdjustment($real, $adj) {

    if (!$adj) return false;

    $adjPmOut = dtrClockOrEmpty($adj['pm_departure'] ?? '');
    if ($adjPmOut !== '') return true;

    $eff = dtrEffectiveRecord($real, $adj);
    [, $out] = dtrInOutOf($eff, $adj);
    return $out !== '' && dtrClockOrEmpty($adj['am_departure'] ?? '') === $out && ($eff['pm_departure'] ?? '') === '';
}

// ---------- the day calculation (twin of dtrRulesDayCalc in dtr-view.php) ----------
//
// $opts: half_session ('AM'|'PM'|null)  half_included (bool)
//        half_start / half_end (event clock times or '')
//        break_start / break_end (configured, or '')
//        cutoff_pm (fallback late reference for an AM half-day)
//
// Returns in, out, late, early_departure, overtime, overtime_approved,
// missing_out, complete, hours (credited), plus the raw out/in seconds.
function dtrDayCalc($schedule, $real, $adj, $opts = []) {

    $eff = dtrEffectiveRecord($real, $adj);
    [$in, $out] = dtrInOutOf($eff, $adj);

    $half     = $opts['half_session'] ?? null;
    $shape    = $schedule ? dtrScheduleShape($schedule) : 'day';
    $schedIn  = $schedule ? dtrSecs($schedule['time_in'] ?? '') : null;
    $schedOut = $schedule ? dtrSecs($schedule['time_out'] ?? '') : null;

    if ($schedOut !== null && $shape === 'overnight') {
        $schedOut += 86400;
    }

    $r = [
        'in' => $in, 'out' => $out,
        'late' => false, 'early_departure' => false,
        'overtime' => false, 'overtime_approved' => false,
        'missing_out' => false, 'complete' => false,
        'hours' => 0.0,
    ];

    $inS = dtrSecs($in);
    $outS = dtrSecs($out);

    // ---- lateness: the person's OWN schedule start, no grace, second precision
    if ($inS !== null && $schedIn !== null) {
        if ($shape === 'day' && $half === 'AM') {
            $ref = dtrSecs($opts['half_end'] ?? '');
            if ($ref === null) $ref = dtrSecs($opts['break_end'] ?? '');
            if ($ref === null) $ref = dtrSecs($opts['cutoff_pm'] ?? '13:00:00');
            $r['late'] = $inS > $ref;
        } elseif ($shape !== 'day' && $half === 'PM') {
            $r['late'] = false;
        } else {
            $r['late'] = $inS > $schedIn;
        }
    }

    if ($inS === null) {
        return $r;                       // no IN at all: nothing else to evaluate
    }

    if ($outS === null) {
        $r['missing_out'] = true;        // real IN without a real/approved OUT
        return $r;
    }

    // ---- timeline (overnight shifts cross midnight)
    if ($outS < $inS) {
        if ($shape === 'overnight') {
            $outS += 86400;
        } else {
            $r['complete'] = true;       // nonsensical order: complete, but no credit
            return $r;
        }
    }

    $r['complete'] = true;
    $approved = dtrOutFromAdjustment($real, $adj);

    // ---- overtime / early departure
    $expectedOut = $schedOut;
    if ($half === 'PM' && $shape === 'day') {
        $he = dtrSecs($opts['half_start'] ?? '');
        if ($he === null) $he = dtrSecs($opts['break_start'] ?? '');
        if ($he !== null) $expectedOut = $he;
    }

    $creditOutS = $outS;

    if ($schedOut !== null) {
        // Overtime: OUT later than the scheduled end by more than the explicit
        // tolerance (DTR_OVERTIME_TOLERANCE_SECONDS).
        if (($outS - $schedOut) > DTR_OVERTIME_TOLERANCE_SECONDS) {
            $r['overtime'] = true;
            $r['overtime_approved'] = $approved;
            // (no_cap = historical shift correction: legacy shift hours were never capped)
            if (!$approved && empty($opts['no_cap'])) {
                $creditOutS = $schedOut;
            }
        }
        if ($expectedOut !== null && !$r['overtime'] && ($expectedOut - $outS) >= DTR_EARLY_DEPARTURE_SECONDS) {
            $r['early_departure'] = true;
        }
    }

    $hours = max(0, $creditOutS - $inS) / 3600;

    // ---- Half Day school change: the affected window is not counted as
    // worked time here; "Included in Total Hours = Yes" is credited by the
    // caller from the schedule (dtrWindowSpanHours).
    if ($half) {

        if ($shape !== 'day') {
            if ($half === 'PM') $hours = 0.0;      // a PM/whole change covers the entire continuous shift
        } else {
            $bs = dtrSecs($opts['break_start'] ?? '');
            $mid = $bs !== null ? $bs : 43200;
            $ws = dtrSecs($opts['half_start'] ?? '');
            $we = dtrSecs($opts['half_end'] ?? '');
            if ($ws === null || $we === null) {
                if ($half === 'AM') { $ws = $schedIn ?? 0; $we = $mid; }
                else                { $ws = $mid; $we = $schedOut ?? 86400; }
            }
            $overlap = max(0, min($creditOutS, $we) - max($inS, $ws));
            $hours = max(0, $hours - $overlap / 3600);
        }
    }

    $r['hours'] = $hours;

    return $r;
}

// ---------- scan routing (pure; used by save-attendance.php and the tests) ----------
//
// ctx: date (Y-m-d), time (H:i:s),
//      schedule / prevSchedule (assigned schedule rows or null),
//      row / prevRow (real attendance rows or null),
//      break (['start','end'] or null)
//
// Returns one of
//   ['action'=>'store','date','column','kind'=>'IN'|'OUT','insert'=>bool,'title']
//   ['action'=>'reject','reason'=>'DUPLICATE_SCAN'|'ATTENDANCE_COMPLETE','date','existing_out','detail']
function dtrRouteScan($ctx) {

    $date = $ctx['date'];
    $time = dtrClockOrEmpty($ctx['time']);
    $sched = $ctx['schedule'] ?? null;
    $prevSched = $ctx['prevSchedule'] ?? null;
    $row = $ctx['row'] ?? null;
    $prevRow = $ctx['prevRow'] ?? null;
    $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $nowTs = strtotime($date . ' ' . $time);

    // ---- accidental-repeat guard: runs before any state routing, so a repeat
    // can never become the next attendance action. Rejected scans are never
    // stored, so they cannot move the basis of the waiting period.
    $repeat = dtrRepeatGuard($date, $nowTs, $sched, $row, $prevRow, $prevDate);
    if ($repeat !== null) {
        return $repeat;
    }

    // ---- an overnight shift that started yesterday and has not aged out
    if ($prevSched && dtrScheduleShape($prevSched) === 'overnight' && $prevRow) {

        [$pIn, $pOut] = dtrInOutOf(dtrEffectiveRecord($prevRow, null));
        $hasOwnIn = $row && (dtrClockOrEmpty($row['am_arrival'] ?? '') !== '' || dtrClockOrEmpty($row['pm_arrival'] ?? '') !== '');
        $windowEnd = strtotime($date . ' ' . dtrClockOrEmpty($prevSched['time_out'])) + DTR_OVERNIGHT_WINDOW_SECONDS;

        if ($pIn !== '' && !$hasOwnIn && $nowTs <= $windowEnd) {

            $lastTs = dtrLastScanTs($prevRow, $prevDate, true);

            if ($pOut === '') {
                if ($lastTs !== null && $nowTs - $lastTs <= DTR_DUPLICATE_SCAN_SECONDS && $nowTs >= $lastTs) {
                    return dtrReject('DUPLICATE_SCAN', $prevDate, '', 'Scan repeated within ' . DTR_DUPLICATE_SCAN_SECONDS . ' second');
                }
                return ['action' => 'store', 'date' => $prevDate, 'column' => 'pm_departure', 'kind' => 'OUT', 'insert' => false, 'title' => 'PM Departure', 'overnight' => true];
            }

            // Closed overnight shift: a further scan is not tomorrow's arrival
            // unless the person's own schedule for today is about to start.
            $todayStart = ($sched && !empty($sched['time_in'])) ? strtotime($date . ' ' . dtrClockOrEmpty($sched['time_in'])) : null;
            if ($todayStart === null || $nowTs < $todayStart - 7200) {
                $reason = ($lastTs !== null && $nowTs - $lastTs <= DTR_DUPLICATE_SCAN_SECONDS && $nowTs >= $lastTs) ? 'DUPLICATE_SCAN' : 'ATTENDANCE_COMPLETE';
                return dtrReject($reason, $prevDate, $pOut, 'Overnight shift already completed');
            }
        }
    }

    // ---- today's shift
    $shape = $sched ? dtrScheduleShape($sched) : 'day';
    $rowIn = ''; $rowOut = '';
    if ($row) {
        [$rowIn, $rowOut] = dtrInOutOf(dtrEffectiveRecord($row, null));
    }

    if ($row) {
        $lastTs = dtrLastScanTs($row, $date, $shape === 'overnight');
        $withinDebounce = $lastTs !== null && $nowTs >= $lastTs && ($nowTs - $lastTs) <= DTR_DUPLICATE_SCAN_SECONDS;

        if ($rowIn !== '' && $rowOut !== '') {
            return dtrReject($withinDebounce ? 'DUPLICATE_SCAN' : 'ATTENDANCE_COMPLETE', $date, $rowOut, $withinDebounce ? 'Scan repeated within ' . DTR_DUPLICATE_SCAN_SECONDS . ' second' : 'Attendance already complete for this shift');
        }

        if ($withinDebounce) {
            return dtrReject('DUPLICATE_SCAN', $date, '', 'Scan repeated within ' . DTR_DUPLICATE_SCAN_SECONDS . ' second');
        }

        if ($rowIn !== '') {

            // Regular teachers (schedule whose configured break is scanned): a scan
            // inside the paid lunch window is the lunch-OUT, and the next scan after
            // it is the lunch-IN — neither closes the day. Shift personnel (breaks
            // not scanned) always go straight to the final OUT below.
            $brk = $ctx['break'] ?? null;

            if ($shape === 'day' && $brk && $brk['source'] === 'default') {

                $rec = dtrEffectiveRecord($row, null);
                $bs = dtrSecs($brk['start']);
                $be = dtrSecs($brk['end']);
                $nowS = dtrSecs($time);
                $inS = dtrSecs($rowIn);
                $endS = $sched ? dtrSecs($sched['time_out'] ?? '') : null;

                if ($rec['am_arrival'] !== '' && $rec['am_departure'] === '' && $rec['pm_arrival'] === ''
                    && $bs !== null && $be !== null
                    && $nowS >= $bs - DTR_LUNCH_SCAN_EARLY_SECONDS && $nowS < $be && $inS < $be) {
                    return ['action' => 'store', 'date' => $date, 'column' => 'am_departure', 'kind' => 'BREAK_OUT', 'insert' => false, 'title' => 'AM Departure'];
                }

                if ($rec['am_departure'] !== '' && $rec['pm_arrival'] === ''
                    && ($endS === null || $nowS < $endS - DTR_EARLY_DEPARTURE_SECONDS)) {
                    return ['action' => 'store', 'date' => $date, 'column' => 'pm_arrival', 'kind' => 'BREAK_IN', 'insert' => false, 'title' => 'PM Arrival'];
                }
            }

            return ['action' => 'store', 'date' => $date, 'column' => 'pm_departure', 'kind' => 'OUT', 'insert' => false, 'title' => 'PM Departure'];
        }
    }

    // first valid scan of the shift = IN
    if ($shape === 'day') {
        $breakEnd = dtrSecs($ctx['break']['end'] ?? '') ?? 43200;
        $column = (dtrSecs($time) >= $breakEnd) ? 'pm_arrival' : 'am_arrival';
    } else {
        $column = 'pm_arrival';
    }

    return [
        'action' => 'store', 'date' => $date, 'column' => $column, 'kind' => 'IN',
        'insert' => !$row, 'title' => $column === 'am_arrival' ? 'AM Arrival' : 'PM Arrival',
    ];
}

function dtrReject($reason, $date, $existingOut, $detail) {
    return ['action' => 'reject', 'reason' => $reason, 'date' => $date, 'existing_out' => $existingOut, 'detail' => $detail];
}

// The basis is the latest ACCEPTED scan across today's row and (for an overnight
// shift) yesterday's row. Only open attendance (no final OUT yet) is guarded;
// closed attendance keeps its existing routing and reasons. Strict "<": exactly
// DTR_REPEAT_GUARD_SECONDS after the last accepted scan is allowed.
function dtrRepeatGuard($date, $nowTs, $sched, $row, $prevRow, $prevDate) {

    $shape = $sched ? dtrScheduleShape($sched) : 'day';
    $lastTs = null;
    $basisRow = null;
    $basisDate = $date;

    if ($row) {
        $ts = dtrLastScanTs($row, $date, $shape === 'overnight');
        if ($ts !== null) {
            $lastTs = $ts;
            $basisRow = $row;
        }
    }

    if ($prevRow) {
        $ts = dtrLastScanTs($prevRow, $prevDate, true);
        if ($ts !== null && ($lastTs === null || $ts > $lastTs)) {
            $lastTs = $ts;
            $basisRow = $prevRow;
            $basisDate = $prevDate;
        }
    }

    if ($lastTs === null || dtrClockOrEmpty($basisRow['pm_departure'] ?? '') !== '') {
        return null;
    }

    $elapsed = $nowTs - $lastTs;
    if ($elapsed < 0 || $elapsed >= DTR_REPEAT_GUARD_SECONDS) {
        return null;
    }

    return dtrReject('DUPLICATE_SCAN', $basisDate, '', 'Repeat scan within ' . DTR_REPEAT_GUARD_SECONDS . ' seconds of the last recorded scan');
}

// Timestamp of the latest real scan stored on a row (an overnight
// shift's OUT time-of-day belongs to the following calendar day).
function dtrLastScanTs($row, $date, $overnight) {

    $last = null;
    $in = null;

    foreach (['am_arrival', 'pm_arrival'] as $c) {
        $v = dtrClockOrEmpty($row[$c] ?? '');
        if ($v !== '') {
            $ts = strtotime($date . ' ' . $v);
            $last = ($last === null || $ts > $last) ? $ts : $last;
            $in = ($in === null || $ts < $in) ? $ts : $in;
        }
    }

    foreach (['am_departure', 'pm_departure'] as $c) {
        $v = dtrClockOrEmpty($row[$c] ?? '');
        if ($v !== '') {
            $ts = strtotime($date . ' ' . $v);
            if ($overnight && $in !== null && $ts < $in) {
                $ts += 86400;
            }
            $last = ($last === null || $ts > $last) ? $ts : $last;
        }
    }

    return $last;
}


// ==================== from dtr/dtr-core.php ====================

// ==========================================================
// SCHEDULE-AWARE HELPERS
//
// A person's OWN schedule row for the weekday (teacher_schedules) is
// the source of truth for when they are expected to work. Ordinary
// day schedules keep the AM / lunch / PM model. Afternoon and
// overnight schedules are single continuous shifts.
//
// dtr/dtr-view.php has a JavaScript twin of these rules — keep them
// in step (the differential test in the project notes compares them).
// ==========================================================

// 'day'       normal daytime schedule (starts before noon, ends same day)
// 'afternoon' starts at/after noon and ends the same day (e.g. 14:00-22:00)
// 'overnight' ends before it starts, i.e. crosses midnight (e.g. 22:00-06:00)
function dtrScheduleShape($schedule) {

    if (!$schedule || empty($schedule['time_in']) || empty($schedule['time_out'])) {
        return 'day';
    }

    $in  = substr($schedule['time_in'], 0, 8);
    $out = substr($schedule['time_out'], 0, 8);

    if ($out < $in) {
        return 'overnight';
    }

    return ($in >= '12:00:00') ? 'afternoon' : 'day';
}

function dtrIsShiftSchedule($schedule) {
    return dtrScheduleShape($schedule) !== 'day';
}

function dtrIsClockTime($value) {
    return is_string($value) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) === 1;
}

// Earliest real arrival scan of the day (a shift can be filed in
// either arrival column depending on the clock time it was scanned).
function dtrShiftArrival($record) {

    $candidates = [];

    foreach (['am_arrival', 'pm_arrival'] as $column) {
        if (!empty($record[$column]) && dtrIsClockTime($record[$column])) {
            $candidates[] = $record[$column];
        }
    }

    if (!$candidates) {
        return '';
    }

    sort($candidates);

    return $candidates[0];
}

// Departure scan of the day — "AUTO" placeholders are not real scans.
function dtrShiftDeparture($record) {

    foreach (['pm_departure', 'am_departure'] as $column) {
        if (!empty($record[$column]) && dtrIsClockTime($record[$column])) {
            return $record[$column];
        }
    }

    return '';
}

// A shift person is late only when the first arrival is after THEIR
// scheduled time in (minute precision) — never against the fixed
// 8:00 / 1:00 PM day cutoffs.
function dtrShiftIsLate($schedule, $record) {

    $arrival = dtrShiftArrival($record);

    if ($arrival === '' || empty($schedule['time_in'])) {
        return false;
    }

    return substr($arrival, 0, 5) > substr($schedule['time_in'], 0, 5);
}

// Hours of one continuous shift: scanned arrival/departure, otherwise
// the scheduled time in/out. No lunch deduction. A departure earlier
// than the arrival means the shift crossed midnight.
function dtrShiftHours($schedule, $record) {

    if (!$schedule) {
        return 0.0;
    }

    $in  = dtrShiftArrival($record) ?: ($schedule['time_in'] ?? '');
    $out = dtrShiftDeparture($record) ?: ($schedule['time_out'] ?? '');

    if (!$in || !$out) {
        return 0.0;
    }

    $hours = (strtotime('1970-01-01 ' . $out) - strtotime('1970-01-01 ' . $in)) / 3600;

    return ($hours < 0) ? $hours + 24 : $hours;
}

// Which AM/PM sessions a schedule occupies. A continuous shift has no
// AM session; its "session" is PM, so a Half Day AM change affects
// nothing of it and a Half Day PM change affects the whole shift.
function dtrSessionAffectsShift($sessions) {
    return in_array('PM', $sessions, true);
}

// Personnel Scheduled to Work: a person listed on a School Schedule
// Change who ALSO has their own schedule for that weekday is not
// covered by that change (it is treated as a normal working day).
// Someone listed but with no schedule that day is unaffected by this —
// nothing is invented for them.
function dtrEventExemptsTeacher($event, $teacherId, $scheduleForDay) {

    if ($teacherId === null || !$scheduleForDay || empty($event['worker_ids'])) {
        return false;
    }

    return in_array((int) $teacherId, array_map('intval', $event['worker_ids']), true);
}


// ==========================================================
// REVIEW PERIODS — first Sunday of each month
//
// A review period starts on the first Sunday of a month and runs to
// the day before the next month's first Sunday. Which period an
// attendance date belongs to is pure arithmetic on the date, so
// nothing is stored and unresolved items are never reassigned or lost.
// ==========================================================

function dtrFirstSunday($year, $month) {

    $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $dayOfWeek = (int) $d->format('w');   // 0 = Sunday

    if ($dayOfWeek !== 0) {
        $d->modify('+' . (7 - $dayOfWeek) . ' days');
    }

    return $d->format('Y-m-d');
}

function dtrReviewPeriodStart($date) {

    $d = new DateTime($date);
    $start = dtrFirstSunday((int) $d->format('Y'), (int) $d->format('n'));

    if ($date >= $start) {
        return $start;
    }

    $previous = (clone $d)->modify('first day of previous month');

    return dtrFirstSunday((int) $previous->format('Y'), (int) $previous->format('n'));
}

function dtrReviewPeriodEnd($periodStart) {

    $next = (new DateTime($periodStart))->modify('first day of next month');
    $nextStart = dtrFirstSunday((int) $next->format('Y'), (int) $next->format('n'));

    return (new DateTime($nextStart))->modify('-1 day')->format('Y-m-d');
}

function dtrReviewPeriodLabel($periodStart) {

    $start = new DateTime($periodStart);
    $end = new DateTime(dtrReviewPeriodEnd($periodStart));

    $startFormat = ($start->format('Y') === $end->format('Y')) ? 'M j' : 'M j, Y';

    return $start->format($startFormat) . ' – ' . $end->format('M j, Y');
}

// ==========================================================
// GET TEACHER DAY STATUS
// Priority: Leave -> Adjustment -> School Event -> Confirmed Absent
//           -> Not Scheduled -> Pending Review (past, unscheduled gap)
//           -> Present/Late
// ==========================================================

function getTeacherDayStatus(
    $date,
    $leaves,
    $adjustments,
    $events,
    $attendanceRow,
    $confirmedAbsences = [],
    $schedules = [],
    $cutoffAM = '08:00:00',
    $cutoffPM = '13:00:00',
    $teacherId = null
) {

    $today = date('Y-m-d');

    // This person's OWN schedule for the weekday — drives the
    // Personnel-Scheduled-to-Work exemption and shift-aware lateness.
    $scheduleForDay = $schedules[strtolower(date('l', strtotime($date)))] ?? null;
    $isShift = dtrIsShiftSchedule($scheduleForDay);

    /* =====================================================
       HALF DAY SCHEDULE CHANGE
       A Half Day event only affects ONE session (its start_time
       before noon = AM, otherwise PM — same rule dtr-view.php uses
       for the DTR). It must not blank out the whole day, so it is
       never returned as the day's status below; instead the
       affected session is simply excluded from the late check.
       ===================================================== */

    $halfDaySession = null;
    $halfEvent = null;

    foreach ($events as $event) {

        if (
            ($event['status'] ?? '') === 'Active'
            && ($event['duration'] ?? '') === 'Half Day'
            && !empty($event['start_time'])
            && $date >= $event['date_from'] && $date <= $event['date_to']
        ) {
            // Scheduled to work through this change: it doesn't apply.
            if (dtrEventExemptsTeacher($event, $teacherId, $scheduleForDay)) {
                continue;
            }

            $halfDaySession = ($event['start_time'] < '12:00:00') ? 'AM' : 'PM';
            $halfEvent = $event;
            break;
        }
    }

    // Attendance rules effective 2026-09-27: lateness is judged against
    // the person's own schedule start (no grace, no fixed 8:00 / 1:00 PM
    // cutoffs) on the effective (real + adjustment) record.
    $newRules = dtrRulesApply($date);
    $statusOpts = [
        'half_session' => $halfDaySession,
        'half_start'   => $halfEvent['start_time'] ?? '',
        'half_end'     => $halfEvent['end_time'] ?? '',
        'cutoff_pm'    => $cutoffPM,
    ];

    /* =====================================================
       1. TEACHER LEAVE
       ===================================================== */

    foreach ($leaves as $leave) {

        if ($leave['status'] === 'Cancelled') {
            continue;
        }

        if ($date >= $leave['leave_from'] && $date <= $leave['leave_until']) {

            return [
                'status' => 'On Leave',
                'detail' => $leave['leave_type'] ?? 'On Leave'
            ];
        }
    }

    /* =====================================================
       2. ATTENDANCE ADJUSTMENT
       ===================================================== */

    if (isset($adjustments[$date])) {

        $adj = $adjustments[$date];

        $hasTimes =
            $adj['am_arrival'] || $adj['am_departure'] ||
            $adj['pm_arrival'] || $adj['pm_departure'];

        if ($hasTimes) {

            $isLate = false;

            if ($newRules) {

                $isLate = dtrDayCalc($scheduleForDay, $attendanceRow, $adj, $statusOpts)['late'];

            } elseif ($isShift) {

                // Afternoon / overnight shift: judged against THEIR time in.
                if ($halfDaySession !== 'PM') {
                    $isLate = dtrShiftIsLate($scheduleForDay, $adj);
                }

            } else {

                if ($halfDaySession !== 'AM' && $adj['am_arrival'] && strtotime($adj['am_arrival']) > strtotime($cutoffAM)) {
                    $isLate = true;
                }

                if ($halfDaySession !== 'PM' && $adj['pm_arrival'] && strtotime($adj['pm_arrival']) > strtotime($cutoffPM)) {
                    $isLate = true;
                }
            }

            return [
                'status' => $isLate ? 'Late' : 'Present',
                'detail' => $adj['adjustment_type'] ?? 'Adjusted'
            ];
        }

        return [
            'status' => 'Adjusted',
            'detail' => $adj['adjustment_type'] ?? 'Adjusted'
        ];
    }

    /* =====================================================
       3. SCHOOL EVENT
       ===================================================== */

    foreach ($events as $event) {

        if ($event['status'] !== 'Active') {
            continue;
        }

        // Half Day changes only affect one session — handled above,
        // the rest of the day is still evaluated normally.
        if (($event['duration'] ?? '') === 'Half Day') {
            continue;
        }

        if ($date >= $event['date_from'] && $date <= $event['date_to']) {

            // Personnel Scheduled to Work: this person has their own
            // schedule today, so the change doesn't apply to them — the
            // day is evaluated like any normal working day below.
            if (dtrEventExemptsTeacher($event, $teacherId, $scheduleForDay)) {
                continue;
            }

            $detail = $event['event_name'] ?? $event['event_type'];

            // School Schedule Changes carry a duration/time range and an
            // optional remark — surface them here so DTR remarks/attendance
            // handling stay accurate without changing this function's
            // return shape (still just status + a detail string).
            if (
                isset($event['duration']) && $event['duration'] === 'Half Day'
                && !empty($event['start_time']) && !empty($event['end_time'])
            ) {
                $detail .= ' (Half Day: '
                    . date('g:i A', strtotime($event['start_time']))
                    . ' - '
                    . date('g:i A', strtotime($event['end_time']))
                    . ')';
            }

            if (!empty($event['remark'])) {
                $detail .= ' - ' . $event['remark'];
            }

            return [
                'status' => 'School Event',
                'detail' => $detail
            ];
        }
    }

    /* =====================================================
       4. CONFIRMED ABSENT
       ===================================================== */

    if (isset($confirmedAbsences[$date])) {

        return [
            'status' => 'Absent',
            'detail' => 'Confirmed'
        ];
    }

    /* =====================================================
       5. RAW ATTENDANCE (has a scan)
       ===================================================== */

    if ($attendanceRow) {

        $amIn = $attendanceRow['am_arrival'] ?? null;
        $pmIn = $attendanceRow['pm_arrival'] ?? null;

        if ($amIn || $pmIn) {

            $isLate = false;

            if ($newRules) {

                $isLate = dtrDayCalc($scheduleForDay, $attendanceRow, null, $statusOpts)['late'];

            } elseif ($isShift) {

                // Afternoon / overnight shift: an arrival at (or before) THEIR
                // scheduled time in is on time — never compared with the
                // 8:00 AM / 1:00 PM day cutoffs.
                if ($halfDaySession !== 'PM') {
                    $isLate = dtrShiftIsLate($scheduleForDay, $attendanceRow);
                }

            } else {

                if ($halfDaySession !== 'AM' && $amIn && strtotime($amIn) > strtotime($cutoffAM)) {
                    $isLate = true;
                }

                if ($halfDaySession !== 'PM' && $pmIn && strtotime($pmIn) > strtotime($cutoffPM)) {
                    $isLate = true;
                }
            }

            return [
                'status' => $isLate ? 'Late' : 'Present',
                'detail' => ''
            ];
        }
    }

    /* =====================================================
       6. NOT SCHEDULED THIS DAY —
          no leave/adjustment/event/confirmed-absence/scan,
          and the teacher simply has no schedule entry for
          this specific day of the week (a Part-Time
          teacher's off-day, or an unscheduled Saturday).
          Never Pending Review, never counted as Absent.
       ===================================================== */

    $dayKey = strtolower(date('l', strtotime($date)));

    if (!isset($schedules[$dayKey])) {

        return [
            'status' => 'Not Scheduled',
            'detail' => ''
        ];
    }

    /* =====================================================
       7. NO SCAN, NOT CONFIRMED —
          Pending Review if the day already passed, otherwise
          it's simply not evaluated yet (future/today).
       ===================================================== */

    if ($date < $today) {

        return [
            'status' => 'Pending Review',
            'detail' => ''
        ];
    }

    // Today (or future) with no scan yet — not Present, not Absent.
    // Just hasn't happened yet.
    return [
        'status' => 'Not Yet Recorded',
        'detail' => ''
    ];
}


// ==========================================================
// DAILY HOURS — PHP COUNTERPART OF THE DTR'S PER-DAY RULES
//
// dtr/dtr-view.php calculates each day's hours in browser
// JavaScript (printDTRWithRemarks), which PHP cannot call. This
// mirrors those rules step for step so the Monthly Summary reports
// exactly the same Total Hours as the individual DTR and Generate
// All DTR. If the DTR's hour rules change, change them here too.
//
// Same precedence as the DTR:
//   1 Leave  2 Attendance adjustment  3 Individual DTR remark
//   4 School schedule change  5 Sunday / unscheduled Saturday
//   6 Confirmed absent  7 Attendance scans (gaps auto-filled)
// ==========================================================

function dtrSpanHours($from, $to, $clampNegative = false) {

    if (!$from || !$to) {
        return 0.0;
    }

    $hours = (strtotime('1970-01-01 ' . $to) - strtotime('1970-01-01 ' . $from)) / 3600;

    return ($clampNegative && $hours < 0) ? 0.0 : $hours;
}

// "Included in Total Hours = Yes" credit: this teacher's OWN scheduled
// hours for the given session(s) — the scan when there is one, otherwise
// the schedule / lunch-break value (never a fixed number of hours).
// Same as creditedEventHours() in dtr-view.php.
function dtrCreditedHours($schedule, $record, $sessions, $lunchOut, $lunchIn) {

    if (!$schedule) {
        return 0.0;
    }

    $record = $record ?: [];

    // Afternoon / overnight shift: one continuous shift, filed under PM.
    if (dtrIsShiftSchedule($schedule)) {
        return dtrSessionAffectsShift($sessions) ? dtrShiftHours($schedule, $record) : 0.0;
    }

    $credited = 0.0;

    if (in_array('AM', $sessions, true)) {
        $credited += dtrSpanHours(
            !empty($record['am_arrival']) ? $record['am_arrival'] : ($schedule['time_in'] ?? null),
            !empty($record['am_departure']) ? $record['am_departure'] : $lunchOut,
            true
        );
    }

    if (in_array('PM', $sessions, true)) {
        $credited += dtrSpanHours(
            !empty($record['pm_arrival']) ? $record['pm_arrival'] : $lunchIn,
            !empty($record['pm_departure']) ? $record['pm_departure'] : ($schedule['time_out'] ?? null),
            true
        );
    }

    return $credited;
}

// Rule options for dtrDayCalc: the configured paid break of the person's
// assigned schedule plus the Half Day window (if any).
function dtrRuleOpts($schedule, $breakCfg, $session, $included, $event, $cutoffPm = '13:00:00', $noCap = false) {

    $b = dtrBreakForSchedule($breakCfg, $schedule);

    return [
        'no_cap'        => $noCap,
        'half_session'  => $session,
        'half_included' => $included,
        'half_start'    => $event['start_time'] ?? '',
        'half_end'      => $event['end_time'] ?? '',
        'break_start'   => $b['start'] ?? '',
        'break_end'     => $b['end'] ?? '',
        'cutoff_pm'     => $cutoffPm,
    ];
}

// Scheduled span of the given session(s), used for the "Included in Total
// Hours = Yes" credit. Paid breaks are part of the span (never deducted).
// An AM window ends / a PM window starts at the configured break start.
function dtrWindowSpanHours($schedule, $sessions, $opts) {

    if (!$schedule) {
        return 0.0;
    }

    $in  = dtrSecs($schedule['time_in'] ?? '');
    $out = dtrSecs($schedule['time_out'] ?? '');

    if ($in === null || $out === null) {
        return 0.0;
    }

    if (dtrScheduleShape($schedule) !== 'day') {
        if (!dtrSessionAffectsShift($sessions)) {
            return 0.0;
        }
        return ($out < $in ? $out + 86400 - $in : $out - $in) / 3600;
    }

    $hasAM = in_array('AM', $sessions, true);
    $hasPM = in_array('PM', $sessions, true);

    if ($hasAM && $hasPM) {
        return max(0, $out - $in) / 3600;
    }

    $mid = dtrSecs($opts['break_start'] ?? '');
    if ($mid === null) $mid = 43200;

    $ws = $hasAM ? $in  : $mid;
    $we = $hasAM ? $mid : $out;

    $es = dtrSecs($opts['half_start'] ?? '');
    $ee = dtrSecs($opts['half_end'] ?? '');
    if ($es !== null && $ee !== null) {
        $ws = max($in, $es);
        $we = min($out, $ee);
    }

    return max(0, $we - $ws) / 3600;
}

function getTeacherDayHours(
    $date,
    $leaves,
    $adjustments,
    $events,
    $remark,
    $attendanceRow,
    $confirmedAbsences,
    $schedules,
    $lunchOut,
    $lunchIn,
    $teacherId = null,
    $breakConfig = null
) {

    $dayKey   = strtolower(date('l', strtotime($date)));
    $dayNo    = (int) date('w', strtotime($date));   // 0 = Sunday
    $schedule = $schedules[$dayKey] ?? null;
    $record   = $attendanceRow ?: [];

    // Attendance rules effective 2026-09-27 (earlier dates keep the legacy math below).
    $newRules = dtrRulesApply($date);
    $breakCfg = [
        'lunch_out' => $lunchOut,
        'lunch_in'  => $lunchIn,
        'entries'   => $breakConfig['entries'] ?? dtrDefaultBreakEntries(),
    ];

    // NARROW historical correction (dates before the effective date): shift / guard
    // personnel are never credited from a scheduled end that was not scanned. Their
    // real IN/OUT (plus adjustment fields) drive the hours; a real IN without a real
    // OUT is unresolved (0 hours, "Missing OUT"). Legacy uncapped out-in math is kept.
    // Regular teachers keep every legacy rule below.
    $shiftLegacy = !$newRules && dtrIsShiftPersonnel($breakCfg, $schedule);
    $useEngine = $newRules || $shiftLegacy;

    /* 1. LEAVE — highest priority, no hours */

    foreach ($leaves as $leave) {

        if (($leave['status'] ?? '') === 'Cancelled') {
            continue;
        }

        if ($date >= $leave['leave_from'] && $date <= $leave['leave_until']) {
            return 0.0;
        }
    }

    /* 2. ATTENDANCE ADJUSTMENT — times get the same gap fill as scans */

    $adj = $adjustments[$date] ?? null;

    if ($adj) {

        $adjAmIn  = $adj['am_arrival'] ?? '';
        $adjAmOut = $adj['am_departure'] ?? '';
        $adjPmIn  = $adj['pm_arrival'] ?? '';
        $adjPmOut = $adj['pm_departure'] ?? '';

        if (!$adjAmIn && !$adjAmOut && !$adjPmIn && !$adjPmOut) {
            return 0.0;   // label-only adjustment (e.g. no times)
        }

        // Effective attendance: the real scans with the adjustment's
        // non-empty fields laid over them (the schedule never fills a gap).
        if ($useEngine) {
            return dtrDayCalc($schedule, $record, $adj, dtrRuleOpts($schedule, $breakCfg, null, false, null, $lunchIn, $shiftLegacy))['hours'];
        }

        // Afternoon / overnight shift: one continuous shift.
        if (dtrIsShiftSchedule($schedule)) {
            return dtrShiftHours($schedule, $adj);
        }

        if (!$adjAmIn && $schedule && !empty($schedule['time_in']))   $adjAmIn  = $schedule['time_in'];
        if (!$adjPmOut && $schedule && !empty($schedule['time_out'])) $adjPmOut = $schedule['time_out'];
        if (!$adjAmOut && $lunchOut) $adjAmOut = $lunchOut;
        if (!$adjPmIn && $lunchIn)   $adjPmIn  = $lunchIn;

        return dtrSpanHours($adjAmIn, $adjAmOut) + dtrSpanHours($adjPmIn, $adjPmOut);
    }

    /* 3. INDIVIDUAL DTR REMARK (this teacher only) */

    if ($remark && ($remark['remark_type'] ?? '') !== 'On Leave') {

        // Absent / Other (and any other type): collapsed row, no hours.
        if (($remark['remark_type'] ?? '') !== 'Official Business') {
            return 0.0;
        }

        // Official Business — not scheduled that day: nothing to credit.
        if (!$schedule) {
            return 0.0;
        }

        $obIncluded = (int) ($remark['included_in_total_hours'] ?? 0) === 1;

        $obSessions = (
            ($remark['duration'] ?? '') === 'Half Day'
            && in_array($remark['half_day_session'] ?? '', ['AM', 'PM'], true)
        ) ? [$remark['half_day_session']] : ['AM', 'PM'];

        $hasAnyScan = !empty($record['am_arrival']) || !empty($record['am_departure'])
                   || !empty($record['pm_arrival']) || !empty($record['pm_departure']);

        if ($newRules) {

            $obOpts = dtrRuleOpts($schedule, $breakCfg, null, $obIncluded, null, $lunchIn);

            // Whole day: the scheduled span when Included = Yes, otherwise nothing.
            if (count($obSessions) === 2) {
                return $obIncluded ? dtrWindowSpanHours($schedule, ['AM', 'PM'], $obOpts) : 0.0;
            }

            // Half Day: hours worked outside the affected window, plus its
            // scheduled span when Included = Yes.
            $obOpts['half_session'] = $obSessions[0];
            $hours = dtrDayCalc($schedule, $record, null, $obOpts)['hours'];

            return $hours + ($obIncluded ? dtrWindowSpanHours($schedule, [$obSessions[0]], $obOpts) : 0.0);
        }

        // Afternoon / overnight shift: no AM session — the whole shift is
        // the "PM" one. A PM (or Whole Day) remark covers it; an AM Half
        // Day remark leaves the shift as a normal day.
        if (dtrIsShiftSchedule($schedule)) {

            if (dtrSessionAffectsShift($obSessions)) {
                return $obIncluded ? dtrShiftHours($schedule, $record) : 0.0;
            }

            return $hasAnyScan ? dtrShiftHours($schedule, $record) : 0.0;
        }

        $total = 0.0;

        foreach (['AM', 'PM'] as $session) {

            // Affected session: credited when Included = Yes, else nothing.
            if (in_array($session, $obSessions, true)) {

                if ($obIncluded) {
                    $total += dtrCreditedHours($schedule, $record, [$session], $lunchOut, $lunchIn);
                }

                continue;
            }

            // Other session of a Half Day: a normal day (same gap fill).
            if (!$hasAnyScan) {
                continue;
            }

            $isAM   = ($session === 'AM');
            $rawIn  = ($isAM ? ($record['am_arrival'] ?? '') : ($record['pm_arrival'] ?? ''));
            $rawOut = ($isAM ? ($record['am_departure'] ?? '') : ($record['pm_departure'] ?? ''));
            $fillIn  = $isAM ? ($schedule['time_in'] ?? null) : $lunchIn;
            $fillOut = $isAM ? $lunchOut : ($schedule['time_out'] ?? null);

            $total += dtrSpanHours($rawIn ?: $fillIn, $rawOut ?: $fillOut, true);
        }

        return $total;
    }

    /* 4. SCHOOL SCHEDULE CHANGE — first active one covering the date */

    $matchedEvent = null;

    foreach ($events as $event) {

        if (($event['status'] ?? '') !== 'Active') {
            continue;
        }

        if ($date >= $event['date_from'] && $date <= $event['date_to']) {

            // Scheduled to work through this change: it doesn't apply.
            if (dtrEventExemptsTeacher($event, $teacherId, $schedule)) {
                continue;
            }

            $matchedEvent = $event;
            break;
        }
    }

    $eventIncluded = $matchedEvent && (int) ($matchedEvent['included_in_total_hours'] ?? 0) === 1;
    $halfSession = null;

    if ($matchedEvent) {

        // Whole Day: 0 hours, or the teacher's scheduled day if Included = Yes.
        if (($matchedEvent['duration'] ?? '') !== 'Half Day') {

            if ($newRules) {
                return $eventIncluded
                    ? dtrWindowSpanHours($schedule, ['AM', 'PM'], dtrRuleOpts($schedule, $breakCfg, null, true, null, $lunchIn))
                    : 0.0;
            }

            return $eventIncluded
                ? dtrCreditedHours($schedule, $record, ['AM', 'PM'], $lunchOut, $lunchIn)
                : 0.0;
        }

        // Half Day: session from the event's start time (before noon = AM).
        if (!empty($matchedEvent['start_time'])) {
            $halfSession = ($matchedEvent['start_time'] < '12:00:00') ? 'AM' : 'PM';
        }
    }

    /* 5. WEEKENDS */

    if ($dayNo === 0) {
        return 0.0;
    }

    if ($dayNo === 6 && !$schedule) {
        return 0.0;
    }

    /* 6. CONFIRMED ABSENT */

    if (isset($confirmedAbsences[$date])) {
        return 0.0;
    }

    /* 7. ATTENDANCE SCANS */

    $rawAmIn  = $record['am_arrival'] ?? '';
    $rawAmOut = $record['am_departure'] ?? '';
    $rawPmIn  = $record['pm_arrival'] ?? '';
    $rawPmOut = $record['pm_departure'] ?? '';

    $hasAnyScan = $rawAmIn || $rawAmOut || $rawPmIn || $rawPmOut;

    if ($useEngine) {

        $evOpts = dtrRuleOpts($schedule, $breakCfg, $halfSession, $eventIncluded, $matchedEvent, $lunchIn, $shiftLegacy);
        $hours  = dtrDayCalc($schedule, $record, null, $evOpts)['hours'];

        if ($halfSession && $eventIncluded && $schedule) {
            $hours += dtrWindowSpanHours($schedule, [$halfSession], $evOpts);
        }

        return $hours;
    }

    if (!$hasAnyScan) {

        if (!$schedule) {
            return 0.0;
        }

        // Half Day + Included = Yes: the affected session is credited
        // from the schedule even without a scan.
        if ($halfSession && $eventIncluded) {
            return dtrCreditedHours($schedule, [], [$halfSession], $lunchOut, $lunchIn);
        }

        return 0.0;
    }

    // Afternoon / overnight shift: one continuous shift (no lunch split).
    // An affected Half Day PM change excludes it unless Included = Yes.
    if (dtrIsShiftSchedule($schedule)) {

        if ($halfSession === 'PM' && !$eventIncluded) {
            return 0.0;
        }

        return dtrShiftHours($schedule, $record);
    }

    $amIn  = $rawAmIn;
    $amOut = $rawAmOut;
    $pmIn  = $rawPmIn;
    $pmOut = $rawPmOut;

    if (!$amIn && $schedule && !empty($schedule['time_in']))   $amIn  = $schedule['time_in'];
    if (!$pmOut && $schedule && !empty($schedule['time_out'])) $pmOut = $schedule['time_out'];
    if (!$amOut && $lunchOut) $amOut = $lunchOut;
    if (!$pmIn && $lunchIn)   $pmIn  = $lunchIn;

    $amHours = dtrSpanHours($amIn, $amOut);
    $pmHours = dtrSpanHours($pmIn, $pmOut);

    // Affected Half Day session is excluded unless Included = Yes.
    if ($halfSession === 'AM' && !$eventIncluded) $amHours = 0.0;
    if ($halfSession === 'PM' && !$eventIncluded) $pmHours = 0.0;

    return $amHours + $pmHours;
}
