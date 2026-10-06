<?php

namespace App\Domain;

use App\Models\Teacher;
use Illuminate\Support\Facades\DB;

/**
 * The read-only personnel lists of the Admin and Principal (ported from
 * admin/admin-personnel-management.php and principal/teacher-list.php).
 */
class PersonnelDirectory
{
    public const TYPE_LABELS = [
        'Academic' => 'Teaching Staff',
        'Non-Academic' => 'Non-Teaching Staff / School Administrator',
    ];

    /**
     * One page of active personnel (with schedules), alphabetical.
     *
     * @return array{rows: array, page: int, totalPages: int}
     */
    public static function page(string $search, int $page, int $perPage, bool $searchIdNumber): array
    {
        $query = Teacher::query()->active()
            ->when($search !== '', function ($q) use ($search, $searchIdNumber) {
                $q->where(function ($q2) use ($search, $searchIdNumber) {
                    $q2->where('fullname', 'like', "%{$search}%");
                    if ($searchIdNumber) {
                        $q2->orWhere('id_number', 'like', "%{$search}%");
                    }
                });
            });

        $totalPages = max(1, (int) ceil((clone $query)->count() / $perPage));
        $page = min(max(1, $page), $totalPages);

        $rows = $query->orderBy('fullname')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(['id', 'id_number', 'fullname', 'department', 'academic_status', 'employment_type', 'barcode', 'photo'])
            ->map(function ($t) {
                $row = $t->toArray();
                $row['schedules'] = DB::table('teacher_schedules')
                    ->where('teacher_id', $t->id)
                    ->orderByRaw("FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')")
                    ->get(['day', 'time_in', 'time_out'])
                    ->map(fn ($s) => (array) $s)
                    ->all();

                return $row;
            })
            ->all();

        return ['rows' => $rows, 'page' => $page, 'totalPages' => $totalPages];
    }

    /** Total / employment type / personnel type counts (unfiltered by search). */
    public static function stats(): array
    {
        $employment = ['Regular' => 0, 'Part-Time' => 0, 'Contractual' => 0];

        DB::table('teachers')->where('is_deleted', 0)
            ->groupBy('employment_type')->selectRaw('employment_type, COUNT(*) AS total')->get()
            ->each(function ($row) use (&$employment) {
                if (isset($employment[$row->employment_type])) {
                    $employment[$row->employment_type] = (int) $row->total;
                }
            });

        $types = [];

        DB::table('teachers')->where('is_deleted', 0)
            ->groupBy('academic_status')->selectRaw('academic_status, COUNT(*) AS total')->get()
            ->each(function ($row) use (&$types) {
                $types[self::TYPE_LABELS[$row->academic_status] ?? (string) $row->academic_status] = (int) $row->total;
            });

        return [
            'total' => Teacher::query()->active()->count(),
            'employment' => $employment,
            'types' => $types,
        ];
    }

    public static function photoUrl(?string $photo): string
    {
        return $photo ? route('photos.personnel', basename($photo)) : asset('img/logo.png');
    }
}
