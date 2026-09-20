<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class AcademicYear extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = ['college_id', 'name', 'code', 'starts_on', 'ends_on', 'status', 'created_by', 'updated_by'];

    protected function casts(): array { return ['starts_on' => 'date', 'ends_on' => 'date']; }

    public function academicTerms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function facultySubjectAssignments(): HasMany
    {
        return $this->hasMany(FacultySubjectAssignment::class);
    }

    public function examinations(): HasMany
    {
        return $this->hasMany(Examination::class);
    }

    public function examSchedules(): HasMany
    {
        return $this->hasMany(ExamSchedule::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $startsOn = $model->starts_on;
            $endsOn = $model->ends_on;

            if ($startsOn === null || $endsOn === null) {
                return;
            }

            // Normalize to comparable values: handle Carbon, DateTime, and string cases.
            $starts = $startsOn instanceof \DateTimeInterface ? $startsOn->format('Y-m-d') : (string) $startsOn;
            $ends = $endsOn instanceof \DateTimeInterface ? $endsOn->format('Y-m-d') : (string) $endsOn;

            if ($ends <= $starts) {
                throw ValidationException::withMessages([
                    'ends_on' => 'The ends on must be after starts on.',
                ]);
            }
        });
    }
}
