<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateInstitutionalSettingRequest;
use App\Models\AcademicYear;
use App\Models\InstitutionalSetting;
use App\Services\Settings\InstitutionalSettingsService;
use App\Services\Settings\InstitutionProfileService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstitutionalSettingController extends Controller
{
    public function index(InstitutionalSettingsService $settings): View
    {
        $this->authorize('viewAny', InstitutionalSetting::class);

        return view('settings.index', [
            'institution' => app(TenantContext::class)->require(),
            'branding' => $settings->branding(),
            'activeYear' => Gate::allows('viewAny', AcademicYear::class) ? AcademicYear::query()->where('status', 'active')->first(['id', 'name']) : null,
        ]);
    }

    public function update(UpdateInstitutionalSettingRequest $request, InstitutionProfileService $profile): RedirectResponse
    {
        $profile->update($request->validated(), $request->file('logo'), $request->user());

        return back()->with('success', 'Institution profile and branding saved for the active college.');
    }

    public function logo(InstitutionalSettingsService $settings): StreamedResponse
    {
        $this->authorize('viewBranding', InstitutionalSetting::class);
        $path = $settings->logoPath();
        $disk = Storage::disk('private');
        abort_unless($path && $disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return $disk->response($path, 'institution-logo', [
            'Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
