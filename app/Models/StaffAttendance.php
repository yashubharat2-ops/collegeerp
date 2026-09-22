<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffAttendance extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUSES = ['present', 'absent', 'late', 'leave', 'holiday'];

    protected $fillable = [
        'college_id', 'faculty_id', 'attendance_date', 'status', 'remarks',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['attendance_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }

    public function faculty(): BelongsTo
    {
        return $this->employee();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
