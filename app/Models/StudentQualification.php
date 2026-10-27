<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One previous academic qualification of a student (Class 10, Class 12,
 * Graduation, Post-Graduation, Other).
 *
 * Distinct from `StudentAcademicRecord` (this college's progression ledger) and
 * from `ExamResult` (this institution's own examinations). See the migration for
 * the reasoning.
 */
class StudentQualification extends Model
{
    use SoftDeletes, BelongsToCollege;

    public const LEVEL_CLASS_10 = 'class_10';
    public const LEVEL_CLASS_12 = 'class_12';
    public const LEVEL_GRADUATION = 'graduation';
    public const LEVEL_POST_GRADUATION = 'post_graduation';
    public const LEVEL_OTHER = 'other';

    public const LEVELS = [
        self::LEVEL_CLASS_10,
        self::LEVEL_CLASS_12,
        self::LEVEL_GRADUATION,
        self::LEVEL_POST_GRADUATION,
        self::LEVEL_OTHER,
    ];

    public const LEVEL_LABELS = [
        self::LEVEL_CLASS_10 => 'Class 10',
        self::LEVEL_CLASS_12 => 'Class 12',
        self::LEVEL_GRADUATION => 'Graduation',
        self::LEVEL_POST_GRADUATION => 'Post Graduation',
        self::LEVEL_OTHER => 'Other qualification',
    ];

    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';
    public const RESULT_COMPARTMENT = 'compartment';
    public const RESULT_AWAITING = 'awaiting';

    public const RESULT_STATUSES = [
        self::RESULT_PASS,
        self::RESULT_FAIL,
        self::RESULT_COMPARTMENT,
        self::RESULT_AWAITING,
    ];

    protected $fillable = [
        'college_id', 'student_id', 'level', 'qualification',
        'examination_year', 'board_university', 'institution',
        'roll_number', 'registration_number',
        'subjects', 'marks_obtained', 'max_marks', 'percentage',
        'grade', 'grade_letter', 'result_status', 'remarks',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'subjects' => 'array',
            'marks_obtained' => 'decimal:2',
            'max_marks' => 'decimal:2',
            'percentage' => 'decimal:2',
            'examination_year' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function levelLabel(): string
    {
        return self::LEVEL_LABELS[$this->level] ?? ucfirst(str_replace('_', ' ', (string) $this->level));
    }
}
