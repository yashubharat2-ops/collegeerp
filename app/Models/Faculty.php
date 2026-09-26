<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const EMPLOYMENT_TYPES = [
        'permanent',
        'contract',
        'visiting',
        'adjunct',
        'full_time',
        'part_time',
        'other',
    ];

    public const STATUSES = ['active', 'inactive'];

    protected $fillable = [
        'college_id',
        'employee_code',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'alternate_phone',
        'gender',
        'date_of_birth',
        // Kept for compatibility with the original Platform Faculty/Staff
        // screen. HR writes the normalized designation_id as well.
        'designation',
        'designation_id',
        'department_id',
        'employment_type',
        'status',
        'joining_date',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'emergency_contact_name',
        'emergency_contact_phone',
        'employment_end_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'date_of_birth' => 'date',
            'employment_end_date' => 'date',
        ];
    }

    /**
     * Audit logs (and any other consumer of the model's morph alias) record the
     * fully-qualified class name for faculty/staff subjects. The `faculty`
     * morph-map alias is intentionally kept for the Inventory issue/assignment
     * recipient keys, which store the short string directly; overriding
     * getMorphClass() here only affects models that resolve their type through
     * the model instance (e.g. AuditLogService) and keeps subtypes such as
     * Employee reporting their own class.
     */
    public function getMorphClass(): string
    {
        return static::class;
    }

    public function setEmployeeCodeAttribute($value): void
    {
        // Codes are compared consistently across SQLite/MySQL collations.
        $this->attributes['employee_code'] = strtoupper(trim((string) $value));
    }

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([$this->first_name, $this->middle_name, $this->last_name], fn ($p) => filled($p));

        return implode(' ', $parts);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Normalized HR designation. The legacy `designation` text attribute is
     * intentionally retained so existing academic screens and records remain
     * compatible; use displayDesignation() when a label is needed.
     */
    public function designationMaster(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'faculty_id');
    }

    public function displayDesignation(): ?string
    {
        return $this->designationMaster?->name ?? $this->designation;
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
