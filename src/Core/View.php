<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @var array<string,mixed> */
    private static array $shared = [];

    /** @param array<string,mixed> $data */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $file = rtrim((string) Config::get('paths.views'), '/\\') . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new HttpException(500, 'View not found: ' . $template);
        }
        $vars = array_merge(self::$shared, $data, [
            'currentUser' => Auth::user(),
            'flashes' => Session::instance()->flashes(),
            'locale' => Translator::locale(),
        ]);
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}

final class Translator
{
    /** @var array<string,array<string,string>> */
    private static array $catalogues = [];
    private static string $locale = 'en';

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, (array) Config::get('app.locales', ['en', 'it']), true) ? $locale : 'en';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param array<string,string|int> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $line = self::catalogue(self::$locale)[$key] ?? self::catalogue('en')[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $line = str_replace([':' . $name, '{' . $name . '}'], (string) $value, $line);
        }

        return $line;
    }

    /** @return array<string,string> */
    private static function catalogue(string $locale): array
    {
        if (!isset(self::$catalogues[$locale])) {
            $file = rtrim((string) Config::get('paths.lang'), '/\\') . '/' . $locale . '.php';
            self::$catalogues[$locale] = is_file($file) ? (array) require $file : [];
        }

        return self::$catalogues[$locale];
    }
}
