<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Structured JSON-lines logger. Secrets are redacted before they reach the
 * disk, so Pterodactyl keys, gateway secrets and card tokens never appear in
 * application logs.
 */
final class Logger
{
    private static ?string $directory = null;

    public static function init(string $directory): void
    {
        self::$directory = rtrim($directory, '/\\');
        if (!is_dir(self::$directory)) {
            @mkdir(self::$directory, 0775, true);
        }
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $event, array $context = []): void
    {
        self::write('debug', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function write(string $level, string $event, array $context = []): void
    {
        $record = [
            'ts' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'context' => self::redact($context),
        ];
        $line = Json::encode($record) . PHP_EOL;
        if (self::$directory === null) {
            self::init(dirname(__DIR__, 2) . '/storage/logs');
        }
        @file_put_contents(self::$directory . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    private static function redact(array $context): array
    {
        $sensitive = ['password', 'secret', 'token', 'authorization', 'api_key', 'apikey', 'card', 'cvv', 'private_key', 'license_key'];
        foreach ($context as $key => $value) {
            $flat = strtolower((string) $key);
            foreach ($sensitive as $needle) {
                if (str_contains($flat, $needle)) {
                    $context[$key] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }

        return $context;
    }
}
