<?php

declare(strict_types=1);

namespace App\Core;

final class App
{
    public static string $basePath = '';
    private static ?Database $db = null;
    private static ?Router $router = null;
    /** @var array<string,mixed> */
    private static array $settings = [];
    private static bool $settingsLoaded = false;

    public static function boot(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/\\');
        Env::load(self::$basePath . '/.env');
        Config::load(self::$basePath . '/config/config.php');
        date_default_timezone_set('UTC');
        Logger::init(self::$basePath . '/storage/logs');
        Translator::setLocale((string) Config::get('app.locale', 'en'));
        self::registerListeners();
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            self::$db = Database::connect();
        }

        return self::$db;
    }

    public static function setDatabase(?Database $db): void
    {
        self::$db = $db;
        self::$settingsLoaded = false;
    }

    public static function router(): Router
    {
        if (self::$router === null) {
            self::$router = new Router();
            $router = self::$router;
            require self::$basePath . '/src/Http/routes.php';
        }

        return self::$router;
    }

    public static function path(string $relative = ''): string
    {
        return self::$basePath . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        if (self::$settingsLoaded) {
            return self::$settings;
        }
        self::$settingsLoaded = true;
        try {
            foreach (self::db()->select('SELECT name, value_json FROM settings') as $row) {
                self::$settings[(string) $row['name']] = Json::value((string) $row['value_json']);
            }
        } catch (\Throwable) {
            self::$settings = [];
        }

        return self::$settings;
    }

    public static function setting(string $name, mixed $default = null): mixed
    {
        $settings = self::settings();

        return $settings[$name] ?? $default;
    }

    public static function setSetting(string $name, mixed $value): void
    {
        $db = self::db();
        $encoded = Json::encode($value);
        $existing = $db->selectOne('SELECT id FROM settings WHERE name = :name', ['name' => $name]);
        if ($existing === null) {
            $db->insert('settings', ['name' => $name, 'value_json' => $encoded, 'updated_at' => Database::now()]);
        } else {
            $db->update('settings', ['value_json' => $encoded, 'updated_at' => Database::now()], 'name = :name', ['name' => $name]);
        }
        self::$settings[$name] = $value;
    }

    public static function feature(string $name): bool
    {
        static $cache = [];
        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }
        try {
            $row = self::db()->selectOne('SELECT enabled FROM feature_flags WHERE name = :name', ['name' => $name]);
            $cache[$name] = $row !== null && (int) $row['enabled'] === 1;
        } catch (\Throwable) {
            $cache[$name] = false;
        }

        return $cache[$name];
    }

    public static function isInstalled(): bool
    {
        if ((string) Config::get('app.key', '') === '') {
            return false;
        }
        try {
            return (int) self::db()->value('SELECT COUNT(*) FROM users') > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function version(): string
    {
        return (string) Config::get('app.version', '0.0.0');
    }

    /** Stable installation fingerprint used for licence binding. */
    public static function fingerprint(): string
    {
        $machine = '';
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $file) {
            if (is_readable($file)) {
                $machine = trim((string) file_get_contents($file));
                break;
            }
        }
        if ($machine === '') {
            $machine = (string) gethostname();
        }
        $host = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST) ?: 'localhost';

        return hash('sha256', $host . '|' . $machine . '|' . Crypto::keyFingerprint());
    }

    private static function registerListeners(): void
    {
        if (class_exists(\App\Domain\Listeners::class)) {
            \App\Domain\Listeners::register();
        }
    }
}
