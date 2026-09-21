<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ExamResultItem — the per-subject outcome of one ExamResult.
 *
 * A snapshot of what the calculation engine decided for one ExamSchedule.
 * Subject / examination / academic year / term / program / section remain owned
 * by ExamSchedule and are only referenced here; the entered marks themselves
 * remain owned by ExamMark.
 *
 * `obtained_marks` stays NULL for absent, withheld and unresolved rows — an
 * absent or withheld subject is never silently converted into a passing score.
 */
class ExamResultItem extends Model
{
    use HasFactory, BelongsToCollege;

    // The per-subject vocabulary is the same controlled vocabulary as the
    // overall result status.
    public const STATUS_PASS = ExamResult::RESULT_PASS;
    public const STATUS_FAIL = ExamResult::RESULT_FAIL;
    public const STATUS_ABSENT = ExamResult::RESULT_ABSENT;
    public const STATUS_WITHHELD = ExamResult::RESULT_WITHHELD;
    public const STATUS_INCOMPLETE = ExamResult::RESULT_INCOMPLETE;

    public const STATUSES = ExamResult::RESULT_STATUSES;

    protected $fillable = [
        'college_id',
        'exam_result_id',
        'exam_schedule_id',
        'subject_id',
        'max_marks',
        'passing_marks',
        'obtained_marks',
        'grade',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'max_marks' => 'decimal:2',
            'passing_marks' => 'decimal:2',
            'obtained_marks' => 'decimal:2',
        ];
    }

    public function examResult(): BelongsTo
    {
        return $this->belongsTo(ExamResult::class);
    }

    public function examSchedule(): BelongsTo
    {
        return $this->belongsTo(ExamSchedule::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function hasScore(): bool
    {
        return $this->obtained_marks !== null;
    }
}
