<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
            'exam_date' => 'date:Y-m-d',
            'max_marks' => 'decimal:2',
            'passing_marks' => 'decimal:2',
        ];
    }

    public function setExamDateAttribute($value): void
    {
        $this->attributes['exam_date'] = $value ? Carbon::parse($value)->format('Y-m-d') : null;
    }

    public function setStartTimeAttribute($value): void
    {
        $this->attributes['start_time'] = $this->normalizeTime($value);
    }

    public function setEndTimeAttribute($value): void
    {
        $this->attributes['end_time'] = $this->normalizeTime($value);
    }

    private function normalizeTime($value): ?string
    {
        if (! $value) {
            return null;
        }

        $val = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $val, $matches)) {
            $seconds = isset($matches[3]) ? (int) $matches[3] : 0;
            return sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], $seconds);
        }

        return $val;
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
