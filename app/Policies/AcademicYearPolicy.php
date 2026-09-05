<?php
namespace App\Policies;
use App\Models\{AcademicYear, User};
class AcademicYearPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('academic-years.view'); }
    public function view(User $user, AcademicYear $year): bool { return $user->hasPermission('academic-years.view', $year->college_id); }
    public function create(User $user): bool { return $user->hasPermission('academic-years.create'); }
    public function update(User $user, AcademicYear $year): bool { return $user->hasPermission('academic-years.update', $year->college_id); }
}
