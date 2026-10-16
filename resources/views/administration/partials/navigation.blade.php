@php
    /**
     * Administration / Settings — the last module of the sidebar.
     *
     * Kept as its own partial because its rows are gated by policies
     * (Gate::allows + model class) rather than by the `permission.slug` checks
     * every other module uses. Which entries exist, in which order, and which
     * URL each one points at is unchanged; only the row markup now comes from
     * the shared sidebar components, and the rows are grouped under three
     * non-interactive sub-labels.
     *
     * The items container keeps `data-navigation="administration-settings"`,
     * the hook tests and tooling use to read this module's entries. The sub-labels
     * are emitted from this loop rather than from <x-nav.sub-group>'s own `perm`
     * prop, because the gate here is a policy, not a permission slug: the label is
     * printed only when the entry that follows it survived the Gate filter, so a
     * policy-filtered sidebar can never show a sub-label with nothing under it.
     *
     * The rows stay direct children of the container (one <a> per entry, preceded
     * at most by a <p>), so an XPath such as
     * `//*[@data-navigation="administration-settings"]/a` keeps matching every row.
     */
    $administrationLinks = collect([
        ['label' => 'Users', 'route' => 'admin.users.index', 'pattern' => 'admin.users.*', 'ability' => 'viewAny', 'model' => App\Models\User::class, 'section' => 'Access control'],
        ['label' => 'Roles', 'route' => 'admin.roles.index', 'pattern' => 'admin.roles.*', 'ability' => 'viewAny', 'model' => App\Models\Role::class, 'section' => 'Access control'],
        ['label' => 'Permissions', 'route' => 'admin.permissions.index', 'pattern' => 'admin.permissions.*', 'ability' => 'viewAny', 'model' => App\Models\Permission::class, 'section' => 'Access control'],
        ['label' => 'Academic Configuration', 'route' => 'admin.academic-config.index', 'pattern' => 'admin.academic-config.*', 'ability' => 'viewAcademicConfiguration', 'model' => App\Models\InstitutionalSetting::class, 'section' => 'Configuration'],
        ['label' => 'Institution Settings', 'route' => 'admin.institution-settings.index', 'pattern' => 'admin.institution-settings.*', 'ability' => 'viewAny', 'model' => App\Models\InstitutionalSetting::class, 'section' => 'Configuration'],
        ['label' => 'Notification Settings', 'route' => 'admin.notification-settings.index', 'pattern' => 'admin.notification-settings.*', 'ability' => 'viewNotifications', 'model' => App\Models\InstitutionalSetting::class, 'section' => 'Configuration'],
        ['label' => 'Audit Logs', 'route' => 'admin.audit-logs.index', 'pattern' => 'admin.audit-logs.*', 'ability' => 'viewAny', 'model' => App\Models\AuditLog::class, 'section' => 'System'],
        ['label' => 'System Settings', 'route' => 'admin.system-settings.index', 'pattern' => 'admin.system-settings.*', 'ability' => 'viewSystem', 'model' => App\Models\InstitutionalSetting::class, 'section' => 'System'],
    ])->filter(fn (array $link): bool => Illuminate\Support\Facades\Gate::allows($link['ability'], $link['model'])
        || ($link['label'] === 'Audit Logs' && Illuminate\Support\Facades\Gate::allows('viewPlatform', App\Models\AuditLog::class)))
        ->values();
@endphp

@if ($administrationLinks->isNotEmpty())
    <x-nav.group id="administration-settings" label="Settings" icon="cog" navigation="administration-settings">
        @php $administrationSection = null; @endphp
        @foreach ($administrationLinks as $administrationLink)
            @if ($administrationLink['section'] !== $administrationSection)
                @php $administrationSection = $administrationLink['section']; @endphp
                <x-nav.sub-group :label="$administrationSection" />
            @endif
            <x-nav.link
                :label="$administrationLink['label']"
                :route="$administrationLink['route']"
                :pattern="$administrationLink['pattern']"
                :params="$administrationLink['label'] === 'Audit Logs' && ! app(App\Support\Tenancy\TenantContext::class)->has() ? ['scope' => 'platform'] : []"
            />
        @endforeach
    </x-nav.group>
@endif
