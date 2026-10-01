<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The account panel's interface preferences: a closed allowlist of keys, each one
 * wired to something the application actually does.
 *
 * This service is intentionally not a general settings store. Anything an
 * administrator owns for a college — branding, academic configuration, notification
 * routing, feature toggles — belongs to InstitutionalSettingsService and its
 * administration screens, and is never read or written here. Only the signed-in user
 * owns the values below; they are resolved for `$user` alone and no college
 * identifier takes part in the lookup, so a preference can neither leak into nor be
 * influenced by another tenant's context.
 */
final class UserPreferenceService
{
    /** Seconds a user's own preferences stay cached; the owner's write clears it. */
    private const CACHE_TTL = 900;

    /**
     * Every supported preference. A key absent from this map cannot be read, written or
     * rendered, which is what keeps the Preferences screen from growing switches the
     * application does not act on.
     */
    public const DEFINITIONS = [
        'sidebar.rail_by_default' => [
            'field' => 'sidebar_rail_by_default',
            'type' => 'boolean',
            'default' => false,
            'label' => 'Open the sidebar collapsed to the icon rail',
            'help' => 'Only applies on wide screens. The sidebar toggle always wins for the page you are on, and your choice is remembered.',
        ],
        'header.show_identity' => [
            'field' => 'header_show_identity',
            'type' => 'boolean',
            'default' => true,
            'label' => 'Show my name and e-mail in the header',
            'help' => 'Turn this off to keep just your initials and the dropdown arrow, both in the header and at the top of the panel.',
        ],
    ];

    /**
     * The keys this screen knows about, in render order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * Stored values for one user merged over the defaults, so callers always receive a
     * complete map and a missing row never renders as "off" by accident.
     *
     * @return array<string, bool|string>
     */
    public function resolved(User $user): array
    {
        return Cache::remember(
            $this->cacheKey($user),
            self::CACHE_TTL,
            fn (): array => $this->load($user)
        );
    }

    public function get(User $user, string $key): bool|string
    {
        $this->assertSupported($key);

        return $this->resolved($user)[$key];
    }

    /**
     * Store one preference for one user. Unknown keys are rejected rather than silently
     * persisted, so no caller can smuggle an arbitrary setting into the table.
     */
    public function put(User $user, string $key, mixed $value): void
    {
        $this->assertSupported($key);
        $definition = self::DEFINITIONS[$key];

        $stored = $definition['type'] === 'boolean'
            ? (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? '1' : '0')
            : (string) $value;

        UserPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'key' => $key],
            ['type' => $definition['type'], 'value' => $stored],
        );

        // The header and sidebar read these through resolved(), so the owner's next
        // request must see the value that was just saved.
        Cache::forget($this->cacheKey($user));
    }

    /**
     * The form field each preference is posted under, so the request, the form and this
     * service cannot drift apart.
     *
     * @return array<string, string> field name => preference key
     */
    public function fieldMap(): array
    {
        $map = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $map[$definition['field']] = $key;
        }

        return $map;
    }

    public function cacheKey(User $user): string
    {
        return 'user-preferences:'.$user->getKey();
    }

    /**
     * @return array<string, bool|string>
     */
    private function load(User $user): array
    {
        // No college identifier takes part in this query, and none could: the table
        // keys a preference by its user alone. `orderBy('key')` keeps the read stable
        // row for row, whatever order the storage engine returns them in.
        $stored = UserPreference::query()
            ->where('user_id', $user->getKey())
            ->whereIn('key', $this->keys())
            ->orderBy('key')
            ->get()
            ->keyBy('key');

        $preferences = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $row = $stored->get($key);
            $preferences[$key] = $row === null
                ? $definition['default']
                : $this->cast($definition['type'], $row->value);
        }

        return $preferences;
    }

    private function cast(string $type, mixed $raw): bool|string
    {
        if ($type === 'boolean') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        return (string) ($raw ?? '');
    }

    private function assertSupported(string $key): void
    {
        if (! array_key_exists($key, self::DEFINITIONS)) {
            throw new InvalidArgumentException("Unsupported user preference [{$key}].");
        }
    }
}
