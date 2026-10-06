<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ReportsController as BaseReportsController;

class ReportsController extends BaseReportsController
{
    protected function role(): string
    {
        return 'admin';
    }
}
