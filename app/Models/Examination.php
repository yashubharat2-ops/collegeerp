<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Examination extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const DEFAULT_EXAM_TYPES = [
        'Internal',
        'Mid Term',
        'Semester',
        'End Semester',
        'Annual',
        'Practical',
    ];

    protected $fillable = [
        'college_id',
        'academic_year_id',
        'academic_term_id',
        'name',
        'code',
        'exam_type',
        'start_date',
        'end_date',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ExamSchedule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->start_date && $model->end_date) {
                $start = $model->start_date instanceof \DateTimeInterface
                    ? $model->start_date->format('Y-m-d')
                    : (string) $model->start_date;
                $end = $model->end_date instanceof \DateTimeInterface
                    ? $model->end_date->format('Y-m-d')
                    : (string) $model->end_date;

                if ($end < $start) {
                    throw ValidationException::withMessages([
                        'end_date' => 'The end date must be on or after start date.',
                    ]);
                }
            }
        });
    }
}
