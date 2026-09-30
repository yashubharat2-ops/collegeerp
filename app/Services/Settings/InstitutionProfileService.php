<?php

namespace App\Services\Settings;

use App\Models\College;
use App\Models\InstitutionalSetting;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/** Institution identity stays on College; only branding uses the existing settings rows. */
class InstitutionProfileService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly InstitutionalSettingsService $settings,
        private readonly SecureFileService $files,
        private readonly AuditLogService $audit,
    ) {}

    public function update(array $data, ?UploadedFile $logo, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', InstitutionalSetting::class);
        $collegeId = (int) $this->tenant->require()->getKey();
        $newPath = null;
        $oldPath = null;

        try {
            if ($logo) {
                $newPath = $this->files->store($logo, 'colleges/'.$collegeId.'/branding');
                if (! $newPath) {
                    throw new RuntimeException('The institution logo could not be stored.');
                }
            }
            DB::transaction(function () use ($data, $actor, $collegeId, $newPath, &$oldPath): void {
                $college = College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('manage', InstitutionalSetting::class);
                $before = $college->only(['name', 'email', 'phone', 'address']);
                $college->update(Arr::only($data, ['name', 'email', 'phone', 'address']));
                $this->audit->record('institution.updated', $college, $before, $college->only(array_keys($before)));

                if (array_key_exists('short_name', $data)) {
                    $oldShortName = $this->settings->branding()['short_name'];
                    $setting = $this->settings->put(InstitutionalSettingsService::SHORT_NAME, $data['short_name']);
                    $this->audit->record('institution.branding_updated', $setting, ['short_name' => $oldShortName], ['short_name' => $setting->value]);
                }

                if ($newPath !== null || ($data['remove_logo'] ?? false)) {
                    $oldPath = $this->settings->logoPath();
                    $setting = $this->settings->put(InstitutionalSettingsService::LOGO_PATH, $newPath);
                    // Audit presence, not filesystem paths or original file names.
                    $this->audit->record('institution.logo_updated', $setting, ['has_logo' => $oldPath !== null], ['has_logo' => $newPath !== null]);
                }
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                $this->files->delete($newPath);
            }
            throw $exception;
        }

        // Only a validated, tenant-owned old path can be deleted, after commit.
        if ($oldPath && $oldPath !== $newPath) {
            $this->files->delete($oldPath);
        }
    }
}
