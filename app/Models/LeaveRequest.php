<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'reason',
        'start_date',
        'end_date',
        'status',
        'file_proof',
        'location',
    ];

    /**
     * Mendapatkan user yang memiliki leave request ini.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}