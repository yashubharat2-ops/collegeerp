@php
    /**
     * Administration / Settings — the last module of the sidebar.
     *
     * Kept as its own partial because its rows are gated by policies
     * (Gate::allows + model class) rather than by the `permission.slug` checks
     * every other module uses. Which entries exist, in which order, and which
     * URL each one points at is unchanged; only the row markup now comes from
     * the shared sidebar components.
     *
     * The items container keeps `data-navigation="administration-settings"`,
     * the hook tests and tooling use to read this module's entries.
     */
    $administrationLinks = collect([
        ['label' => 'Users', 'route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'ability' => 'viewAny', 'model' => App\Models\User::class],
        ['label' => 'Roles', 'route' => 'admin.roles.index', 'pattern' => 'admin.roles.*', 'ability' => 'viewAny', 'model' => App\Models\Role::class],
        ['label' => 'Permissions', 'route' => 'admin.permissions.index', 'pattern' => 'admin.permissions.*', 'ability' => 'viewAny', 'model' => App\Models\Permission::class],
        ['label' => 'Academic Configuration', 'route' => 'admin.academic-config.index', 'pattern' => 'admin.academic-config.*', 'ability' => 'viewAcademicConfiguration', 'model' => App\Models\InstitutionalSetting::class],
        ['label' => 'Institution Settings', 'route' => 'admin.institution-settings.index', 'pattern' => 'admin.institution-settings.*', 'ability' => 'viewAny', 'model' => App\Models\InstitutionalSetting::class],
        ['label' => 'Notification Settings', 'route' => 'admin.notification-settings.index', 'pattern' => 'admin.notification-settings.*', 'ability' => 'viewNotifications', 'model' => App\Models\InstitutionalSetting::class],
        ['label' => 'Audit Logs', 'route' => 'admin.audit-logs.index', 'pattern' => 'admin.audit-logs.*', 'ability' => 'viewAny', 'model' => App\Models\AuditLog::class],
        ['label' => 'System Settings', 'route' => 'admin.system-settings.index', 'pattern' => 'admin.system-settings.*', 'ability' => 'viewSystem', 'model' => App\Models\InstitutionalSetting::class],
    ])->filter(fn (array $link): bool => Illuminate\Support\Facades\Gate::allows($link['ability'], $link['model'])
        || ($link['label'] === 'Audit Logs' && Illuminate\Support\Facades\Gate::allows('viewPlatform', App\Models\AuditLog::class)));
@endphp

@if ($administrationLinks->isNotEmpty())
    <x-nav.group id="administration-settings" label="Administration / Settings" icon="cog" navigation="administration-settings">
        @foreach ($administrationLinks as $administrationLink)
            <x-nav.link
                :label="$administrationLink['label']"
                :route="$administrationLink['route']"
                :pattern="$administrationLink['pattern']"
                :params="$administrationLink['label'] === 'Audit Logs' && ! app(App\Support\Tenancy\TenantContext::class)->has() ? ['scope' => 'platform'] : []"
            />
        @endforeach
    </x-nav.group>
@endif
