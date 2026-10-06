<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\AttendanceReportController as BaseAttendanceReportController;

class AttendanceReportController extends BaseAttendanceReportController
{
    protected function role(): string
    {
        return 'superadmin';
    }
}
