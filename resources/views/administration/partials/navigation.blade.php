@php
    $administrationLinks = collect([
        ['Users', '👥', 'admin.users.index', 'admin.users.*', 'viewAny', App\Models\User::class],
        ['Roles', '🛡', 'admin.roles.index', 'admin.roles.*', 'viewAny', App\Models\Role::class],
        ['Permissions', '🔑', 'admin.permissions.index', 'admin.permissions.*', 'viewAny', App\Models\Permission::class],
        ['Academic Configuration', '📚', 'admin.academic-config.index', 'admin.academic-config.*', 'viewAcademicConfiguration', App\Models\InstitutionalSetting::class],
        ['Institution Settings', '🏛', 'admin.institution-settings.index', 'admin.institution-settings.*', 'viewAny', App\Models\InstitutionalSetting::class],
        ['Notification Settings', '🔔', 'admin.notification-settings.index', 'admin.notification-settings.*', 'viewNotifications', App\Models\InstitutionalSetting::class],
        ['Audit Logs', '🗒', 'admin.audit-logs.index', 'admin.audit-logs.*', 'viewAny', App\Models\AuditLog::class],
        ['System Settings', '⚙', 'admin.system-settings.index', 'admin.system-settings.*', 'viewSystem', App\Models\InstitutionalSetting::class],
    ])->filter(fn ($link) => Illuminate\Support\Facades\Gate::allows($link[4], $link[5])
        || ($link[0] === 'Audit Logs' && Illuminate\Support\Facades\Gate::allows('viewPlatform', App\Models\AuditLog::class)));
@endphp
@if($administrationLinks->isNotEmpty())
<x-sidebar-section title="Administration / Settings" icon="settings">
<div data-navigation="administration-settings">
    @foreach($administrationLinks as [$label, $icon, $route, $pattern])
        @php($active = request()->routeIs($pattern) || ($label === 'Institution Settings' && request()->routeIs('settings.index')))
        <a class="nav-link {{ $active ? 'bg-indigo-600 text-white' : '' }}" href="{{ route($route, $label === 'Audit Logs' && ! app(\App\Support\Tenancy\TenantContext::class)->has() ? ['scope' => 'platform'] : []) }}" @if($active) aria-current="page" @endif>
            <span aria-hidden="true">{{ $icon }}</span><span>{{ $label }}</span>
        </a>
    @endforeach
</div>
</x-sidebar-section>
@endif
