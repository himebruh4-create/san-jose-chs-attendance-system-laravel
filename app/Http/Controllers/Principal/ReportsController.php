<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\ReportsController as BaseReportsController;

class ReportsController extends BaseReportsController
{
    protected function role(): string
    {
        return 'principal';
    }
}
