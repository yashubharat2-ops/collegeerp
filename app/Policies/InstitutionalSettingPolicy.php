<?php
namespace App\Policies;
use App\Models\{InstitutionalSetting, User};
class InstitutionalSettingPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('settings.view'); }
    public function update(User $user, InstitutionalSetting $setting): bool { return $user->hasPermission('settings.update', $setting->college_id); }
}
