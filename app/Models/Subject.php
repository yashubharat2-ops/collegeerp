<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const TYPES = ['theory', 'practical', 'core', 'elective', 'lab', 'seminar', 'other'];
    public const STATUSES = ['active', 'inactive'];

    protected $fillable = [
        'college_id',
        'department_id',
        'code',
        'name',
        'short_name',
        'subject_type',
        'credits',
        'max_marks',
        'passing_marks',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'decimal:2',
            'max_marks' => 'decimal:2',
            'passing_marks' => 'decimal:2',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FacultySubjectAssignment::class);
    }

    public function examSchedules(): HasMany
    {
        return $this->hasMany(ExamSchedule::class);
    }
}
