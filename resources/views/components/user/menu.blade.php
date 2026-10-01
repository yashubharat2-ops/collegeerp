@php
    /**
     * College ERP — the signed-in user panel (top-right of the header).
     *
     * This is the account entry point of the application: it replaced the user
     * block that used to sit in the left sidebar, so the sidebar now flows
     * brand → search → Dashboard → navigation and the account lives in the
     * header only, once, where a SaaS shell normally keeps it.
     *
     * Everything here is presentation, and every row is honest about the
     * application behind it:
     *   - the identity rows read `auth()->user()`, which the request already
     *     loaded, so no query and no tenant/RBAC change is involved;
     *   - Notifications links to the real `notifications.index` screen and only
     *     renders when the user holds `notifications.view` — the same gate the
     *     Communication Management sidebar row uses, so the menu can never
     *     offer a link that answers 403;
     *   - My Profile, Change Password and Preferences link to the account
     *     screens this application really serves (routes `profile.edit`,
     *     `password.change.edit` and `preferences.edit`, all declared under
     *     `auth` in routes/web.php). They are ordinary links: nothing in this
     *     panel is a placeholder;
     *   - Help & Support has no route — there is no help desk or documentation
     *     module in this build — so it stays a disabled row carrying the reason
     *     in its tooltip rather than being invented as a fake link;
     *   - whether the name and e-mail appear beside the avatar is the user's own
     *     `header.show_identity` preference, the same mechanism the Preferences
     *     screen writes;
     *   - Logout posts to the existing `logout` route exactly as the previous
     *     header did — same verb, same CSRF, same controller, same redirect;
     *   - System Settings deliberately does NOT live here. It stays under
     *     Administration / Settings → System Settings, where the policy gates it.
     *
     * The panel is a <details>/<summary> disclosure, so it opens and closes with
     * no JavaScript at all; public/js/erp-user-menu.js only adds what a disclosure
     * cannot do by itself (close on outside click, Escape, arrow keys) and
     * public/css/erp-user-menu.css draws it. Both are linked by
     * resources/views/layouts/app.blade.php, no bundler needed.
     */
    $menuUser = auth()->user();
    $menuWords = preg_split('/\s+/u', trim((string) ($menuUser?->name ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $menuInitials = mb_strtoupper(implode('', array_map(
        fn (string $word): string => mb_substr($word, 0, 1),
        array_slice($menuWords, 0, 2)
    ))) ?: 'U';
    // Fully qualified like the other views do, so the panel does not depend on
    // the facade alias being registered, and the route itself is probed so the
    // component stays renderable if the Communication module is ever unmounted.
    $menuMaySeeNotifications = ($menuUser?->hasPermission('notifications.view') ?? false)
        && \Illuminate\Support\Facades\Route::has('notifications.index');
    /*
     * The identity block is the user's to hide. It defaults to on and the Preferences
     * screen owns the value; the avatar and the disclosure's accessible name are not
     * part of it, so the control is never an anonymous button.
     */
    $menuShowIdentity = true;
    if ($menuUser !== null) {
        $menuShowIdentity = (bool) app(\App\Services\Settings\UserPreferenceService::class)
            ->resolved($menuUser)['header.show_identity'];
    }
@endphp

<details class="erp-user-menu" data-user-menu>
    <summary class="erp-user-menu__trigger" data-user-menu-trigger aria-label="Account menu for {{ $menuUser?->name }}">
        <span class="erp-user-menu__avatar" aria-hidden="true">{{ $menuInitials }}</span>
        @if ($menuShowIdentity)
            <span class="erp-user-menu__id">
                <span class="erp-user-menu__name">{{ $menuUser?->name }}</span>
                <span class="erp-user-menu__email">{{ $menuUser?->email }}</span>
            </span>
        @endif
        <span class="erp-user-menu__caret" aria-hidden="true"><x-nav.icon name="chevron-down" :size="14" /></span>
    </summary>
    <div class="erp-user-menu__panel" id="erp-user-menu-panel" role="menu" aria-label="Account">
        <div class="erp-user-menu__head">
            <span class="erp-user-menu__avatar erp-user-menu__avatar--lg" aria-hidden="true">{{ $menuInitials }}</span>
            @if ($menuShowIdentity)
                <span class="erp-user-menu__head-text">
                    <span class="erp-user-menu__head-name">{{ $menuUser?->name }}</span>
                    <span class="erp-user-menu__head-email">{{ $menuUser?->email }}</span>
                </span>
            @endif
        </div>
        <div class="erp-user-menu__list">
            <a class="erp-user-menu__item" role="menuitem" tabindex="-1" href="{{ route('profile.edit') }}">
                <span class="erp-user-menu__icon"><x-nav.icon name="user" :size="16" /></span><span class="erp-user-menu__label">My Profile</span>
            </a>
            <a class="erp-user-menu__item" role="menuitem" tabindex="-1" href="{{ route('password.change.edit') }}">
                <span class="erp-user-menu__icon"><x-nav.icon name="lock" :size="16" /></span><span class="erp-user-menu__label">Change Password</span>
            </a>
            <a class="erp-user-menu__item" role="menuitem" tabindex="-1" href="{{ route('preferences.edit') }}">
                <span class="erp-user-menu__icon"><x-nav.icon name="sliders" :size="16" /></span><span class="erp-user-menu__label">Preferences</span>
            </a>
            @if ($menuMaySeeNotifications)
                <a class="erp-user-menu__item" role="menuitem" tabindex="-1" href="{{ route('notifications.index') }}">
                    <span class="erp-user-menu__icon"><x-nav.icon name="bell" :size="16" /></span><span class="erp-user-menu__label">Notifications</span>
                </a>
            @else
                <span class="erp-user-menu__item erp-user-menu__item--muted" role="menuitem" tabindex="-1" aria-disabled="true" title="Your role does not include the notifications permission.">
                    <span class="erp-user-menu__icon"><x-nav.icon name="bell" :size="16" /></span><span class="erp-user-menu__label">Notifications</span>
                </span>
            @endif
            <span class="erp-user-menu__item erp-user-menu__item--muted" role="menuitem" tabindex="-1" aria-disabled="true" title="Help &amp; Support is not configured in this build: no help desk, documentation or support route exists to open.">
                <span class="erp-user-menu__icon"><x-nav.icon name="question" :size="16" /></span><span class="erp-user-menu__label">Help &amp; Support</span>
            </span>
        </div>
        <div class="erp-user-menu__separator" role="none"></div>
        <form method="POST" action="{{ route('logout') }}" class="erp-user-menu__logout" data-user-menu-logout>
            @csrf
            <button type="submit" class="erp-user-menu__item erp-user-menu__item--danger" role="menuitem" tabindex="-1">
                <span class="erp-user-menu__icon"><x-nav.icon name="logout" :size="16" /></span><span class="erp-user-menu__label">Logout</span>
            </button>
        </form>
    </div>
</details>
