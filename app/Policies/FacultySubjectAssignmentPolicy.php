<?php

namespace App\Policies;

use App\Models\FacultySubjectAssignment;
use App\Models\User;

class FacultySubjectAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('faculty_subject_assignments.view');
    }

    public function view(User $user, FacultySubjectAssignment $assignment): bool
    {
        return $user->hasPermission('faculty_subject_assignments.view', $assignment->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('faculty_subject_assignments.create');
    }

    public function update(User $user, FacultySubjectAssignment $assignment): bool
    {
        return $user->hasPermission('faculty_subject_assignments.update', $assignment->college_id);
    }

    public function delete(User $user, FacultySubjectAssignment $assignment): bool
    {
        return $user->hasPermission('faculty_subject_assignments.delete', $assignment->college_id);
    }
}
