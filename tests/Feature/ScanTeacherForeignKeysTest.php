<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * scan_photos and attendance_rejected_scans belong to an existing person:
 * a scan record cannot point at nobody, and a person with scan records
 * cannot be deleted (personnel are archived, never deleted).
 */
class ScanTeacherForeignKeysTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_08_122303_add_teacher_foreign_keys_to_scan_tables.php';

    private function scanPhoto(?int $teacherId): int
    {
        return DB::table('scan_photos')->insertGetId([
            'teacher_id' => $teacherId,
            'attendance_date' => '2026-10-05',
            'scanned_at' => '2026-10-05 07:00:00',
            'outcome' => $teacherId ? 'accepted' : 'not_found',
        ]);
    }

    private function rejectedScan(int $teacherId): int
    {
        return DB::table('attendance_rejected_scans')->insertGetId([
            'teacher_id' => $teacherId,
            'attempted_at' => '2026-10-05 07:01:00',
            'attendance_date' => '2026-10-05',
            'reason' => 'DUPLICATE_SCAN',
        ]);
    }

    public function test_a_scan_photo_cannot_point_at_a_person_who_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        $this->scanPhoto(999999);
    }

    public function test_a_rejected_scan_cannot_point_at_a_person_who_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        $this->rejectedScan(999999);
    }

    public function test_a_scan_photo_of_an_unknown_barcode_needs_no_person(): void
    {
        $id = $this->scanPhoto(null);

        $this->assertDatabaseHas('scan_photos', ['id' => $id, 'teacher_id' => null]);
    }

    public function test_a_person_with_scan_records_cannot_be_deleted(): void
    {
        $withPhoto = $this->teacher();
        $withRejected = $this->teacher();
        $this->scanPhoto($withPhoto->id);
        $this->rejectedScan($withRejected->id);

        foreach ([$withPhoto, $withRejected] as $teacher) {
            try {
                DB::table('teachers')->where('id', $teacher->id)->delete();
                $this->fail("Teacher {$teacher->id} was deleted despite having scan records.");
            } catch (QueryException) {
            }
        }

        $this->assertDatabaseHas('scan_photos', ['teacher_id' => $withPhoto->id]);
        $this->assertDatabaseHas('attendance_rejected_scans', ['teacher_id' => $withRejected->id]);
    }

    public function test_changing_a_persons_id_carries_their_scan_records_along(): void
    {
        $teacher = $this->teacher();
        $this->scanPhoto($teacher->id);
        $this->rejectedScan($teacher->id);

        DB::table('teachers')->where('id', $teacher->id)->update(['id' => 777777]);

        $this->assertDatabaseHas('scan_photos', ['teacher_id' => 777777]);
        $this->assertDatabaseHas('attendance_rejected_scans', ['teacher_id' => 777777]);
    }

    public function test_the_migration_stops_with_a_clear_message_when_orphan_rows_exist(): void
    {
        $teacher = $this->teacher();
        $this->scanPhoto($teacher->id);
        Schema::withoutForeignKeyConstraints(function () {
            $this->scanPhoto(424242);
            $this->scanPhoto(424242);
            $this->rejectedScan(515151);
        });
        $migration = require base_path(self::MIGRATION);

        try {
            $migration->assertNoOrphans();
            $this->fail('The orphan check passed although orphan rows exist.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                "Cannot add the teacher foreign keys. Nothing was changed.\n"
                ."scan_photos: 2 row(s) point to teacher id(s) that do not exist: 424242\n"
                ."attendance_rejected_scans: 1 row(s) point to teacher id(s) that do not exist: 515151\n"
                .'Restore those personnel records or remove the rows, then run `php artisan migrate` again.',
                $e->getMessage()
            );
        }
    }

    public function test_the_orphan_check_passes_when_every_row_has_a_person(): void
    {
        $teacher = $this->teacher();
        $this->scanPhoto($teacher->id);
        $this->scanPhoto(null);
        $this->rejectedScan($teacher->id);
        $migration = require base_path(self::MIGRATION);

        $migration->assertNoOrphans();

        $this->addToAssertionCount(1);
    }
}
