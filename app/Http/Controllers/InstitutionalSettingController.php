<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateInstitutionalSettingRequest;
use App\Models\InstitutionalSetting;
use App\Services\Audit\AuditLogService;
use App\Services\Settings\InstitutionalSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InstitutionalSettingController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', InstitutionalSetting::class);
        return view('settings.index', ['settings' => InstitutionalSetting::latest()->get()]);
    }

    public function update(UpdateInstitutionalSettingRequest $request, InstitutionalSettingsService $service, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();
        $setting = $service->put($data['key'], $data['value'], $data['type']);
        $audit->record('institutional_setting.updated', $setting, [], ['key' => $setting->key, 'type' => $setting->type]);
        return back()->with('success', 'Institutional setting saved.');
    }
}
