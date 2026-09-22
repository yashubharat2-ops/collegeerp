<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR-facing name for the existing Platform Faculty/Staff record.
 *
 * This is an Eloquent alias over the `faculties` table, not a second employee
 * table. It lets HR services and integrations speak in employee terminology
 * while academic features continue to use Faculty and the same relationships.
 */
class Employee extends Faculty
{
    protected $table = 'faculties';

    public function designationMaster(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'faculty_id');
    }

    public function getDisplayDesignationAttribute(): ?string
    {
        return $this->designationMaster?->name ?? $this->designation;
    }
}
