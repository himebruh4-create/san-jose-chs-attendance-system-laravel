<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherSchedule extends Model
{
    public $timestamps = false;

    protected $fillable = ['teacher_id', 'day', 'time_in', 'time_out'];
}
