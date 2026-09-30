<?php

namespace App\Services\Settings;

use App\Models\InstitutionalSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class InstitutionalSettingsService
{
    public const SHORT_NAME = 'branding.short_name';

    public const LOGO_PATH = 'branding.logo_path';

    public function put(string $key, mixed $value, string $type = 'string', bool $public = false): InstitutionalSetting
    {
        $collegeId = app(TenantContext::class)->require()->getKey();

        return DB::transaction(function () use ($collegeId, $key, $value, $type, $public): InstitutionalSetting {
            $setting = InstitutionalSetting::firstOrNew(['college_id' => $collegeId, 'key' => $key]);
            if (! $setting->exists) {
                $setting->created_by = auth()->id();
            }
            $setting->fill([
                'value' => $value === null ? null : (is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR)),
                'type' => $type, 'is_public' => $public, 'updated_by' => auth()->id(),
            ])->save();

            return $setting;
        });
    }

    /** Read an allowlist only, never dump arbitrary stored settings into the UI. */
    public function branding(): array
    {
        $college = app(TenantContext::class)->college();
        if (! $college) {
            return ['short_name' => null, 'has_logo' => false];
        }
        $values = InstitutionalSetting::query()->whereIn('key', [self::SHORT_NAME, self::LOGO_PATH])->pluck('value', 'key');

        return [
            'short_name' => $values[self::SHORT_NAME] ?? null,
            'has_logo' => $this->isLogoPath($values[self::LOGO_PATH] ?? null, (int) $college->getKey()),
        ];
    }

    public function logoPath(): ?string
    {
        $collegeId = app(TenantContext::class)->id();
        if ($collegeId === null) {
            return null;
        }
        $path = InstitutionalSetting::query()->where('key', self::LOGO_PATH)->value('value');

        return $this->isLogoPath($path, $collegeId) ? $path : null;
    }

    public function isLogoPath(?string $path, int $collegeId): bool
    {
        return $path !== null && preg_match('#^colleges/'.$collegeId.'/branding/[a-zA-Z0-9]{40}\\.(png|jpg|jpeg|webp)$#D', $path) === 1;
    }
}
