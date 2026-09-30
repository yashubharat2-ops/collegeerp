<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\InstitutionalSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemSettingsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewSystem', InstitutionalSetting::class);

        return view('administration.system-settings.index', [
            'institution' => app(TenantContext::class)->college(),
            'switchableColleges' => $request->user()->isSuperAdmin()
                ? College::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                : $request->user()->colleges()->where('status', 'active')->orderBy('name')->get(['colleges.id', 'name']),
            // Only these safe deployment-managed values may reach the page.
            // No env(), config dump, provider hosts, credentials or API keys.
            'platformSettings' => $request->user()->isSuperAdmin() ? [
                'Application timezone' => config('app.timezone', 'UTC'),
                'Application locale' => config('app.locale', 'en'),
                'Session idle lifetime (minutes)' => (int) config('session.lifetime', 120),
                'Internal communication page size' => (int) config('communication.per_page', 15),
                'Communication attachment limit (KB)' => (int) config('communication.attachments.max_kb', 5120),
            ] : [],
        ]);
    }
}
