<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('students.view');
    }

    public function view(User $user, Student $student): bool
    {
        return $user->hasPermission('students.view', $student->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('students.create');
    }

    public function update(User $user, Student $student): bool
    {
        return $user->hasPermission('students.update', $student->college_id);
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->hasPermission('students.delete', $student->college_id);
    }
}
