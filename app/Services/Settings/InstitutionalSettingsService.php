<?php
namespace App\Services\Settings;
use App\Models\InstitutionalSetting;
use Illuminate\Support\Facades\DB;
class InstitutionalSettingsService
{
    public function put(string $key, mixed $value, string $type = 'string', bool $public = false): InstitutionalSetting
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->require()->id;
        return DB::transaction(fn () => InstitutionalSetting::updateOrCreate(['college_id' => $collegeId, 'key' => $key], ['value' => is_scalar($value) ? (string) $value : json_encode($value), 'type' => $type, 'is_public' => $public, 'updated_by' => auth()->id(), 'created_by' => auth()->id()]));
    }
}
