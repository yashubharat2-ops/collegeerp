<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Program (course of study) owned by a college.
 *
 * Department is optional: a program may sit at college level (no department).
 * When set, the department must belong to the same college as the program. The
 * model never treats a cross-tenant department as valid on read: Department
 * carries the CollegeScope global scope, so $program->department only resolves
 * within the active tenant, which for every tenant-scoped query is the same
 * college as the owning Program row (Program is scoped identically). Foreign
 * colleges therefore hydrate as null, mirroring how Department::campus() works.
 *
 * Limitation (deferred by design, not invented here): nothing at the model
 * layer rejects persisting a foreign-college department_id (the FK only checks
 * existence, as with departments.campus_id in the migration). The write-side
 * guard belongs to the Form Request as Rule::exists('departments', 'id')
 * ->where('college_id', $collegeId), mirroring StoreDepartmentRequest, in the
 * next task.
 */
class Program extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = ['college_id', 'department_id', 'name', 'code', 'short_name', 'description', 'status'];

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
}
