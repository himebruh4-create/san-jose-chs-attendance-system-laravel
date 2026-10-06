<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AttendanceReportController as BaseAttendanceReportController;

class AttendanceReportController extends BaseAttendanceReportController
{
    protected function role(): string
    {
        return 'admin';
    }
}
