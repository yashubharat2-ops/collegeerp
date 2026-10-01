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
     *   - My Profile, Change Password, Preferences and Help & Support have no
     *     route in this application (there is no self-service account page: the
     *     password flow is the guest reset screen, and user records are
     *     maintained under Administration / Settings → Users), so they render
     *     as disabled rows with the reason in their tooltip instead of being
     *     invented as fake links;
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
@endphp

<details class="erp-user-menu" data-user-menu>
    <summary class="erp-user-menu__trigger" data-user-menu-trigger aria-label="Account menu for {{ $menuUser?->name }}">
        <span class="erp-user-menu__avatar" aria-hidden="true">{{ $menuInitials }}</span>
        <span class="erp-user-menu__id">
            <span class="erp-user-menu__name">{{ $menuUser?->name }}</span>
            <span class="erp-user-menu__email">{{ $menuUser?->email }}</span>
        </span>
        <span class="erp-user-menu__caret" aria-hidden="true"><x-nav.icon name="chevron-down" :size="14" /></span>
    </summary>
    <div class="erp-user-menu__panel" id="erp-user-menu-panel" role="menu" aria-label="Account">
        <div class="erp-user-menu__head">
            <span class="erp-user-menu__avatar erp-user-menu__avatar--lg" aria-hidden="true">{{ $menuInitials }}</span>
            <span class="erp-user-menu__head-text">
                <span class="erp-user-menu__head-name">{{ $menuUser?->name }}</span>
                <span class="erp-user-menu__head-email">{{ $menuUser?->email }}</span>
            </span>
        </div>
        <div class="erp-user-menu__list">
            <span class="erp-user-menu__item erp-user-menu__item--muted" role="menuitem" tabindex="-1" aria-disabled="true" title="Users have no self-service profile page: name and e-mail are maintained under Administration / Settings → Users.">
                <span class="erp-user-menu__icon"><x-nav.icon name="user" :size="16" /></span><span class="erp-user-menu__label">My Profile</span>
            </span>
            <span class="erp-user-menu__item erp-user-menu__item--muted" role="menuitem" tabindex="-1" aria-disabled="true" title="This ERP has no in-app password form. Use the reset link on the sign-in screen, or ask an administrator to set a new password.">
                <span class="erp-user-menu__icon"><x-nav.icon name="lock" :size="16" /></span><span class="erp-user-menu__label">Change Password</span>
            </span>
            <span class="erp-user-menu__item erp-user-menu__item--muted" role="menuitem" tabindex="-1" aria-disabled="true" title="Nothing is stored per user yet: the sidebar rail width and the college context are kept in this browser, not in a preferences record.">
                <span class="erp-user-menu__icon"><x-nav.icon name="sliders" :size="16" /></span><span class="erp-user-menu__label">Preferences</span>
            </span>
            @if ($menuMaySeeNotifications)
                <a class="erp-user-menu__item" role="menuitem" tabindex="-1" href="{{ route('notifications.index') }}">
                    <span class="erp-user-menu__icon"><x-nav.icon name="bell" :size="16" /></span><span class="erp-user-menu__label">Notifications</span>
                </a>
            @else
                <span class="erp-user-menu__item erp-user-menu__item--muted" role="menuitem" tabindex="-1" aria-disabled="true" title="Your role does not include the notifications permission.">
                    <span class="erp-user-menu__icon"><x-nav.icon name="bell" :size="16" /></span><span class="erp-user-menu__label">Notifications</span>
                </span>
            @endif
            <span class="erp-user-menu__item erp-user-menu__item--muted" role="menuitem" tabindex="-1" aria-disabled="true" title="No help centre is wired into this build.">
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
