<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env reader. Real environment variables always win over the file so
 * systemd EnvironmentFile / Docker env keep working.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    /** @var array<string,bool> */
    private static array $loaded = [];

    public static function load(string $path): void
    {
        if (isset(self::$loaded[$path])) {
            return;
        }
        self::$loaded[$path] = true;
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '' && !array_key_exists($key, self::$values)) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values[$key] ?? null;
        if ($value === null) {
            $fromSystem = getenv($key);
            $value = $fromSystem === false ? null : $fromSystem;
        }
        if ($value === null) {
            return $default;
        }
        return match (strtolower(trim((string) $value))) {
            'true', 'yes', 'on' => true,
            'false', 'no', 'off' => false,
            'null' => null,
            default => $value,
        };
    }
}

/** Dot-notation configuration repository. */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    /** @param array<string,mixed> $items */
    public static function set(array $items): void
    {
        self::$items = $items;
    }

    public static function load(string $file): void
    {
        self::$items = is_file($file) ? (array) require $file : [];
    }

    /** Deep-merges values into the current configuration (used by tests and plugins). */
    public static function merge(array $items): void
    {
        self::$items = array_replace_recursive(self::$items, $items);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

final class Str
{
    public static function random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }
        [$user, $domain] = explode('@', $email, 2);
        $keep = max(1, (int) floor(mb_strlen($user) / 3));

        return mb_substr($user, 0, $keep) . str_repeat('*', max(2, mb_strlen($user) - $keep)) . '@' . $domain;
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes = intdiv($bytes, 1024);
            $i++;
        }

        return $bytes . ' ' . $units[$i];
    }

    public static function limit(string $value, int $length = 180): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1) . '…';
    }
}

final class Json
{
    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Decodes any JSON scalar/array/object without forcing an array. */
    public static function value(?string $value): mixed
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return json_decode($value, true);
    }

    /** @return array<string,mixed> */
    public static function decode(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
