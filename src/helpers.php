<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Money;
use App\Core\Session;
use App\Core\Translator;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** @param array<string,string|int> $replace */
function __(string $key, array $replace = []): string
{
    return Translator::get($key, $replace);
}

function url(string $path = ''): string
{
    $base = rtrim((string) config('app.url', ''), '/');

    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__) . '/public/assets/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '';

    return url('/assets/' . $path) . ($version !== '' ? '?v=' . $version : '');
}

/** Cents → "12,50" for a price input field. */
function money_input(int $cents): string
{
    return Money::toInput($cents);
}

/** Small inline SVG icon from the built-in set (see resources/views/partials/icons.php). */
function icon(string $name, string $class = 'icon'): string
{
    static $icons = null;
    $icons ??= require dirname(__DIR__) . '/resources/views/partials/icons.php';
    $path = $icons[$name] ?? $icons['dot'];

    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function money(int $cents, ?string $currency = null, bool $symbol = true): string
{
    return Money::format($cents, $currency ?? (string) config('app.currency', 'EUR'), $symbol);
}

function csrf_field(): string
{
    return Csrf::field();
}

function old(string $key, mixed $default = null): mixed
{
    return Session::instance()->old($key, $default);
}

function flash(string $type, string $message): void
{
    Session::instance()->flash($type, $message);
}

/** @return array<string,mixed>|null */
function auth_user(): ?array
{
    return Auth::user();
}

function feature(string $name): bool
{
    return App::feature($name);
}

function setting(string $name, mixed $default = null): mixed
{
    return App::setting($name, $default);
}

function route(string $name, array $params = []): string
{
    return App::router()->url($name, $params);
}

function fmt_date(?string $utc, string $format = 'd/m/Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone((string) config('app.timezone', 'Europe/Rome')))->format($format);
    } catch (Throwable) {
        return (string) $utc;
    }
}

function current_path(): string
{
    return '/' . trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');
}

function is_active(string $path): bool
{
    return str_starts_with(current_path(), rtrim($path, '/')) && $path !== '/';
}

function status_badge(string $status): string
{
    $map = [
        'active' => 'ok', 'paid' => 'ok', 'installing' => 'info', 'pending' => 'info',
        'pending_payment' => 'warn', 'review' => 'warn', 'suspended' => 'warn', 'waiting_staff' => 'warn',
        'waiting_customer' => 'info', 'open' => 'info', 'failed' => 'bad', 'terminated' => 'muted',
        'cancelled' => 'muted', 'refunded' => 'muted', 'closed' => 'muted', 'void' => 'muted', 'rejected' => 'bad',
    ];
    $class = $map[$status] ?? 'muted';

    return '<span class="badge badge-' . $class . '">' . e(__($status)) . '</span>';
}

/**
 * True when the current request reached PHP over HTTPS. X-Forwarded-Proto is
 * honoured only with TRUST_PROXY=true (TLS terminated by a load balancer), so
 * a client cannot fake HTTPS on a plain-HTTP installation.
 *
 * @param array<string,mixed>|null $server defaults to $_SERVER
 */
function request_is_https(?array $server = null): bool
{
    $server ??= $_SERVER;
    $https = (string) ($server['HTTPS'] ?? '');
    if ($https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    if (\App\Core\Env::get('TRUST_PROXY', false) === true) {
        return strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }

    return false;
}

/**
 * How the installation is reached, derived from APP_URL:
 * ['scheme' => http|https, 'host' => ..., 'is_ip' => bool, 'https' => bool].
 *
 * @return array{scheme:string,host:string,is_ip:bool,https:bool}
 */
function app_address(?string $appUrl = null): array
{
    $appUrl ??= (string) config('app.url', '');
    $scheme = strtolower((string) (parse_url($appUrl, PHP_URL_SCHEME) ?: 'http'));
    $host = trim((string) (parse_url($appUrl, PHP_URL_HOST) ?: ''), '[]');

    return [
        'scheme' => $scheme,
        'host' => $host,
        'is_ip' => $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false,
        'https' => $scheme === 'https',
    ];
}

/**
 * SESSION_SECURE -> Secure flag of the session cookie. true/false are taken
 * as they are; "auto" (or any other value) follows the current request, so
 * an installation reached by IP over HTTP can still log in, and the cookie
 * becomes Secure as soon as the same site is served over HTTPS.
 *
 * @param array<string,mixed>|null $server defaults to $_SERVER
 */
function session_cookie_secure(mixed $setting, ?array $server = null): bool
{
    if (is_bool($setting)) {
        return $setting;
    }
    if ($server === null && PHP_SAPI === 'cli') {
        return false;
    }

    return request_is_https($server);
}
