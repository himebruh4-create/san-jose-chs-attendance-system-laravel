<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Ties kiosk scan records to the person they belong to. ON DELETE RESTRICT
// (as on attendance) keeps the evidence: personnel are archived with
// is_deleted, never deleted, so nothing in the app is blocked by it.
// scan_photos.teacher_id stays nullable (unknown barcodes have no person).
return new class extends Migration
{
    /** Tables whose teacher_id gets the foreign key. */
    private const TABLES = ['scan_photos', 'attendance_rejected_scans'];

    public function up(): void
    {
        $this->assertNoOrphans();

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('teacher_id')->references('id')->on('teachers')
                    ->cascadeOnUpdate()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['teacher_id']);
            });
        }
    }

    /**
     * Stops before any change when a row points at a person who does not
     * exist, naming the table and ids, instead of failing halfway with a
     * raw database error.
     */
    public function assertNoOrphans(): void
    {
        $problems = [];

        foreach (self::TABLES as $table) {
            $orphans = DB::table($table.' as x')
                ->whereNotNull('x.teacher_id')
                ->whereNotExists(fn ($query) => $query->from('teachers as t')->whereColumn('t.id', 'x.teacher_id'))
                ->distinct()
                ->orderBy('x.teacher_id')
                ->pluck('x.teacher_id');

            if ($orphans->isNotEmpty()) {
                $rows = DB::table($table)->whereIn('teacher_id', $orphans)->count();
                $problems[] = "{$table}: {$rows} row(s) point to teacher id(s) that do not exist: ".$orphans->take(20)->implode(', ')
                    .($orphans->count() > 20 ? ' …' : '');
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Cannot add the teacher foreign keys. Nothing was changed.\n".implode("\n", $problems)
                ."\nRestore those personnel records or remove the rows, then run `php artisan migrate` again."
            );
        }
    }
};
