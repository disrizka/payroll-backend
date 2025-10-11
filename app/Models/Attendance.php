<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'check_in_time',
        'check_in_location',
        'check_out_time',
        'check_out_location',
        'date',
    ];
}