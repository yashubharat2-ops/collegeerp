<?php
namespace App\Policies;
use App\Models\{Program, User};
class ProgramPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('programs.view'); }
    public function view(User $user, Program $program): bool { return $user->hasPermission('programs.view', $program->college_id); }
    public function create(User $user): bool { return $user->hasPermission('programs.create'); }
    public function update(User $user, Program $program): bool { return $user->hasPermission('programs.update', $program->college_id); }
    public function delete(User $user, Program $program): bool { return $user->hasPermission('programs.delete', $program->college_id); }
}
