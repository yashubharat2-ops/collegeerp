<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicTerm extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const TYPES = ['semester', 'term', 'trimester', 'other'];
    public const STATUSES = ['active', 'inactive'];

    protected $fillable = [
        'college_id',
        'academic_year_id',
        'name',
        'code',
        'type',
        'sequence',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
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
        return $this->hasMany(FacultySubjectAssignment::class, 'academic_term_id');
    }
}
