<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Values the team can change without a deploy.
 *
 * ── Why every read goes through here ───────────────────────────────────────
 * These are read on nearly every request — the dashboard asks how long a deal
 * can be quiet, the digest asks how long a handover may sit. Hitting the
 * database each time for four small integers would be a query per page for no
 * reason, so the whole table is cached as one array and invalidated on write.
 *
 * A key that has never been written falls back to config/settings.php, which
 * means a fresh install works before anybody opens the Settings page, and a key
 * added in a later release does not need a data migration to be usable.
 */
class Settings
{
    private const CACHE_KEY = 'settings.all';

    /**
     * Everything, as key => raw string value, with defaults filled in.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );

        return [...self::defaults(), ...$stored];
    }

    /**
     * An integer setting.
     *
     * Clamped to the bounds declared in config, so a value written before those
     * bounds existed — or straight into the database by hand — still produces
     * behaviour somebody chose rather than behaviour nobody did.
     */
    public static function int(string $key): int
    {
        $value = (int) (self::all()[$key] ?? 0);
        $field = self::field($key);

        if ($field === null) {
            return $value;
        }

        return max((int) $field['min'], min((int) $field['max'], $value));
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'updated_by' => $userId],
        );

        self::forget();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return config('settings.defaults', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        return config('settings.fields', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function field(string $key): ?array
    {
        foreach (self::fields() as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The fields with their current values, ready to render.
     *
     * `is_default` travels with each one so the page can say which values are
     * still as they shipped — useful when somebody is trying to work out why a
     * reminder fires when it does.
     *
     * @return list<array<string, mixed>>
     */
    public static function forDisplay(): array
    {
        $current = self::all();
        $defaults = self::defaults();

        return array_map(
            static fn (array $field): array => [
                ...$field,
                'value' => (int) ($current[$field['key']] ?? 0),
                'default' => (int) ($defaults[$field['key']] ?? 0),
                'is_default' => (int) ($current[$field['key']] ?? 0) === (int) ($defaults[$field['key']] ?? 0),
            ],
            self::fields(),
        );
    }
}
