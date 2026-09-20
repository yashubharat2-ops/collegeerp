<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class ExamSchedule extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'college_id',
        'examination_id',
        'academic_year_id',
        'academic_term_id',
        'program_id',
        'section_id',
        'subject_id',
        'faculty_id',
        'campus_id',
        'exam_date',
        'start_time',
        'end_time',
        'room',
        'max_marks',
        'passing_marks',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'max_marks' => 'decimal:2',
            'passing_marks' => 'decimal:2',
        ];
    }

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
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
            if ($model->start_time && $model->end_time) {
                if ($model->end_time <= $model->start_time) {
                    throw ValidationException::withMessages([
                        'end_time' => 'The end time must be after start time.',
                    ]);
                }
            }
            if ($model->max_marks !== null && (float) $model->max_marks <= 0) {
                throw ValidationException::withMessages([
                    'max_marks' => 'The maximum marks must be greater than 0.',
                ]);
            }
            if ($model->max_marks !== null && $model->passing_marks !== null) {
                if ((float) $model->passing_marks < 0 || (float) $model->passing_marks > (float) $model->max_marks) {
                    throw ValidationException::withMessages([
                        'passing_marks' => 'The passing marks must be between 0 and maximum marks.',
                    ]);
                }
            }
        });
    }
}
