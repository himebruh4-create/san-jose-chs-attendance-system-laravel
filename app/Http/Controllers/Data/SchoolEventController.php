<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * School Schedule Changes and their "Personnel Scheduled to Work" (Super
 * Admin). Ported from dtr/save-school-event.php, update-school-event.php,
 * delete-school-event.php and event-personnel.php.
 */
class SchoolEventController extends Controller
{
    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request)
    {
        $id = (int) $request->input('id');

        if ($id <= 0 || ! DB::table('school_events')->where('id', $id)->exists()) {
            return $this->fail('Invalid schedule change.');
        }

        return $this->save($request, $id);
    }

    public function deactivate(Request $request)
    {
        $id = (int) $request->input('id');

        if ($id <= 0) {
            return $this->fail('Invalid event ID.');
        }

        DB::table('school_events')->where('id', $id)->update(['status' => 'Inactive']);
        Audit::log('school_event.deactivated', 'school_event', $id);

        return $this->ok(['message' => 'School event deactivated successfully.']);
    }

    private function save(Request $request, ?int $id)
    {
        $eventName = self::cleanName((string) $request->input('event_name'));
        $eventType = trim((string) $request->input('event_type', 'Holiday'));
        $dateFrom = trim((string) $request->input('date_from'));
        $dateTo = trim((string) $request->input('date_to'));
        $status = trim((string) $request->input('status', 'Active'));
        $duration = trim((string) $request->input('duration', 'Whole Day'));
        $startTime = trim((string) $request->input('start_time'));
        $endTime = trim((string) $request->input('end_time'));
        $remark = trim((string) $request->input('remark'));
        $includedRaw = trim((string) $request->input('included_in_total_hours'));

        if (! in_array($includedRaw, ['0', '1'], true)) {
            return $this->fail('Please choose whether this is included in total hours.');
        }

        if ($eventName === '') {
            return $this->fail('Event name is required.');
        }

        if ($dateFrom === '' || $dateTo === '') {
            return $this->fail('Start and end date are required.');
        }

        if (! $this->date($dateFrom)) {
            return $this->fail('Invalid start date.');
        }

        if (! $this->date($dateTo)) {
            return $this->fail('Invalid end date.');
        }

        if ($dateFrom > $dateTo) {
            return $this->fail('End date cannot be earlier than the start date.');
        }

        if (! in_array($eventType, ['Holiday', 'Asynchronous', 'Other'], true)) {
            $eventType = 'Holiday';
        }

        if (! in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        if (! in_array($duration, ['Whole Day', 'Half Day'], true)) {
            $duration = 'Whole Day';
        }

        if ($duration === 'Half Day') {
            if ($startTime === '' || $endTime === '') {
                return $this->fail('Start and end time are required for a Half Day schedule change.');
            }

            if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || ! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
                return $this->fail('Please enter valid start and end times.');
            }

            if ($endTime <= $startTime) {
                return $this->fail('End time must be later than start time.');
            }
        } else {
            // Whole Day — the time range is not applicable.
            $startTime = '';
            $endTime = '';
        }

        try {
            $personnelIds = $this->personnelIds($request);
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage());
        }

        $values = [
            'event_name' => mb_substr($eventName, 0, 255),
            'event_type' => $eventType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => $status,
            'duration' => $duration,
            'included_in_total_hours' => (int) $includedRaw,
            'start_time' => $startTime !== '' ? $startTime : null,
            'end_time' => $endTime !== '' ? $endTime : null,
        ];

        try {
            $savedId = DB::transaction(function () use ($id, $values, $remark, $personnelIds) {
                if ($id === null) {
                    // The remark is set on create only; editing leaves an existing one as is.
                    $id = DB::table('school_events')->insertGetId($values + ['remark' => $remark !== '' ? mb_substr($remark, 0, 255) : null]);
                } else {
                    DB::table('school_events')->where('id', $id)->update($values);
                }

                // The submitted list replaces the saved one.
                DB::table('school_event_personnel')->where('school_event_id', $id)->delete();
                DB::table('school_event_personnel')->insert(
                    array_map(fn ($teacherId) => ['school_event_id' => $id, 'teacher_id' => $teacherId], $personnelIds)
                );

                return $id;
            });
        } catch (Throwable $e) {
            report($e);

            return $this->fail($id === null
                ? 'Unable to save the schedule change. Please try again.'
                : 'Unable to update the schedule change. Please try again.', 500);
        }

        Audit::log($id === null ? 'school_event.created' : 'school_event.updated', 'school_event', $savedId, $values + ['personnel' => $personnelIds]);

        return $id === null
            ? $this->ok(['message' => 'School event added successfully.', 'id' => $savedId])
            : $this->ok(['message' => 'School schedule change updated successfully.']);
    }

    /**
     * personnel_ids[] (or a JSON array): distinct ids of ACTIVE personnel.
     */
    private function personnelIds(Request $request): array
    {
        $raw = $request->input('personnel_ids', []);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            $raw = [];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (! is_scalar($value) || ! ctype_digit((string) $value)) {
                throw new \DomainException('Invalid personnel selection.');
            }
            $ids[(int) $value] = true;
        }

        $ids = array_keys($ids);

        if (! $ids) {
            return [];
        }

        $valid = DB::table('teachers')->where('is_deleted', 0)->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();

        if (count($valid) !== count($ids)) {
            throw new \DomainException('One or more selected personnel are no longer available.');
        }

        return $valid;
    }

    /**
     * Trims and collapses spaces. Unlike the native-PHP version it does not
     * lower-case the name, which turned "II" into "Ii".
     */
    public static function cleanName(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value));
    }
}
