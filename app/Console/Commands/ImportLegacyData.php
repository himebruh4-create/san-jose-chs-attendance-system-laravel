<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies every row of the native-PHP database (connection "legacy",
 * database san_jose_chs) into this application's database, keeping all IDs.
 *
 * Attendance rows whose personnel no longer exist (the legacy table had no
 * foreign key) go to archived_attendance with every original column, so
 * nothing is lost and the new attendance table can keep its foreign key.
 * The import ends by checking that every legacy row was accounted for.
 */
class ImportLegacyData extends Command
{
    protected $signature = 'legacy:import
        {--fresh : Empty the application tables first (required when they already hold data)}';

    protected $description = 'Import all data from the native-PHP database (san_jose_chs)';

    /** Application tables in the order they are emptied (children first). */
    private const TABLES = [
        'scan_photos', 'audit_logs', 'school_event_personnel', 'school_events', 'dtr_remarks',
        'teacher_leaves', 'confirmed_absences', 'attendance_rejected_scans', 'attendance_adjustments',
        'archived_attendance', 'attendance', 'teacher_schedules', 'teachers', 'password_reset_requests',
        'accounts', 'department_options', 'system_settings',
    ];

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        try {
            $legacyDb = $legacy->getDatabaseName();
            $legacy->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot connect to the legacy database: '.$e->getMessage());

            return self::FAILURE;
        }

        $hasData = collect(self::TABLES)->contains(fn ($t) => DB::table($t)->exists());

        if ($hasData && ! $this->option('fresh')) {
            $this->error('The application tables already hold data. Re-run with --fresh to replace them.');

            return self::FAILURE;
        }

        $this->info("Importing from `{$legacyDb}` into `".DB::getDatabaseName().'`');

        DB::beginTransaction();

        try {
            if ($hasData) {
                // DELETE (not TRUNCATE) so the whole import stays one transaction.
                foreach (self::TABLES as $table) {
                    DB::table($table)->delete();
                }
            }

            $copied = [];

            $copied['accounts'] = $this->copy('accounts', fn ($r) => [
                'id' => $r->id,
                'full_name' => $r->full_name,
                'email' => $r->email,
                'password' => $r->password,
                'role' => $r->role,
                'security_question' => $r->security_question,
                'security_answer_hash' => $r->security_answer_hash,
                'is_deleted' => $r->is_deleted,
                'deleted_at' => $r->deleted_at,
                'deleted_by' => $r->deleted_by,
                'created_at' => $r->created_at,
            ]);

            $copied['password_reset_requests'] = $this->copy('password_reset_requests', fn ($r) => (array) $r);

            // teachers.schedule_session is NULL in every legacy row and is dropped.
            $copied['teachers'] = $this->copy('teachers', fn ($r) => [
                'id' => $r->id,
                'id_number' => $r->id_number,
                'fullname' => $r->fullname,
                'department' => $r->department,
                'academic_status' => $r->academic_status,
                'employment_type' => $r->employment_type,
                'barcode' => $r->barcode,
                'photo' => $r->photo,
                'is_deleted' => $r->is_deleted,
                'deleted_at' => $r->deleted_at,
                'deleted_by' => $r->deleted_by,
                'created_at' => $r->created_at,
            ]);

            $this->guardDroppedColumn('teachers', 'schedule_session');

            $copied['teacher_schedules'] = $this->copy('teacher_schedules', fn ($r) => (array) $r);

            // Attendance: rows of existing personnel keep their scans; the
            // legacy-only columns (id = 0, id_number, fullname, department,
            // schedule_session, status, overtime) carried no information for
            // them (verified before the migration). Rows of personnel that no
            // longer exist are archived whole.
            $teacherIds = DB::table('teachers')->pluck('id')->flip();
            $attendance = 0;
            $archived = 0;

            $legacy->table('attendance')->orderBy('teacher_id')->orderBy('date')->get()
                ->each(function ($r) use ($teacherIds, &$attendance, &$archived) {
                    $scans = [
                        'date' => $r->date,
                        'am_arrival' => $this->clock($r->am_arrival),
                        'am_departure' => $this->clock($r->am_departure),
                        'pm_arrival' => $this->clock($r->pm_arrival),
                        'pm_departure' => $this->clock($r->pm_departure),
                        'attendance_status' => $r->attendance_status,
                    ];

                    if (isset($teacherIds[$r->teacher_id])) {
                        DB::table('attendance')->insert(['teacher_id' => $r->teacher_id] + $scans);
                        $attendance++;
                    } else {
                        DB::table('archived_attendance')->insert($scans + [
                            'original_teacher_id' => $r->teacher_id,
                            'fullname' => $r->fullname,
                            'department' => $r->department,
                            'legacy_id_number' => (string) $r->id_number,
                            'legacy_schedule_session' => $r->schedule_session,
                            'legacy_status' => $r->status,
                            'legacy_overtime' => $r->overtime,
                            'archive_reason' => 'Imported from the native-PHP system: personnel record no longer exists',
                        ]);
                        $archived++;
                    }
                });

            $copied['attendance'] = $attendance + $archived;
            $this->line(sprintf('  %-28s %5d  (%d active, %d archived: personnel no longer exist)', 'attendance', $attendance + $archived, $attendance, $archived));

            // attendance_id pointed at the legacy attendance.id, which was 0
            // in every row; it is re-linked to the new primary key here.
            $copied['attendance_adjustments'] = $this->copy('attendance_adjustments', function ($r) {
                $row = (array) $r;
                $row['attendance_id'] = DB::table('attendance')
                    ->where('teacher_id', $r->teacher_id)->where('date', $r->adjustment_date)->value('id');

                return $row;
            });

            $copied['attendance_rejected_scans'] = $this->copy('attendance_rejected_scans', fn ($r) => (array) $r);
            $copied['confirmed_absences'] = $this->copy('confirmed_absences', fn ($r) => (array) $r);
            $copied['teacher_leaves'] = $this->copy('teacher_leaves', fn ($r) => (array) $r);
            $copied['dtr_remarks'] = $this->copy('dtr_remarks', fn ($r) => (array) $r);
            $copied['school_events'] = $this->copy('school_events', fn ($r) => (array) $r);
            $copied['school_event_personnel'] = $this->copy('school_event_personnel', fn ($r) => (array) $r);
            $copied['department_options'] = $this->copy('department_options', fn ($r) => (array) $r);
            $copied['system_settings'] = $this->copy('system_settings', fn ($r) => (array) $r);

            // Every legacy row must be accounted for.
            foreach ($copied as $table => $count) {
                $expected = $legacy->table($table)->count();
                if ($expected !== $count) {
                    throw new \RuntimeException("{$table}: legacy has {$expected} rows, imported {$count}.");
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Import failed, nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Import complete. Every legacy row is accounted for.');

        return self::SUCCESS;
    }

    /** Copies one table row by row through $map and reports the count. */
    private function copy(string $table, callable $map): int
    {
        $count = 0;

        DB::connection('legacy')->table($table)->orderBy(
            DB::connection('legacy')->getSchemaBuilder()->hasColumn($table, 'id') ? 'id' : DB::raw('1')
        )->get()->each(function ($row) use ($table, $map, &$count) {
            DB::table($table)->insert($map($row));
            $count++;
        });

        $this->line(sprintf('  %-28s %5d', $table, $count));

        return $count;
    }

    /** Refuses to drop a legacy column that holds any value. */
    private function guardDroppedColumn(string $table, string $column): void
    {
        $nonEmpty = DB::connection('legacy')->table($table)
            ->whereNotNull($column)->where($column, '<>', '')->count();

        if ($nonEmpty > 0) {
            throw new \RuntimeException("{$table}.{$column} holds data in {$nonEmpty} rows; it would be lost.");
        }
    }

    /** Legacy am_departure was varchar; every scan column is TIME now. */
    private function clock(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            throw new \RuntimeException("Unreadable scan time '{$value}'.");
        }

        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
