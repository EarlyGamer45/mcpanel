<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;

final class Kernel
{
    public static function boot(): void
    {
        $router = App::router();
        $router->middleware = [
            'auth' => static function (Request $request, callable $next): Response {
                if (!Auth::check()) {
                    return Response::redirect(url('/login') . '?next=' . rawurlencode($request->path));
                }

                return $next($request);
            },
            'guest' => static function (Request $request, callable $next): Response {
                if (Auth::check()) {
                    return Response::redirect(url('/client'));
                }

                return $next($request);
            },
            'admin' => static function (Request $request, callable $next): Response {
                if (!Auth::check()) {
                    return Response::redirect(url('/login') . '?next=' . rawurlencode($request->path));
                }
                $user = Auth::user();
                if ($user === null || !Auth::isAdmin()) {
                    throw new HttpException(403, __('forbidden'));
                }
                if (!self::ipAllowed($request->ip())) {
                    Logger::warning('security.admin_ip_blocked', ['ip' => $request->ip(), 'user' => (int) $user['id']]);
                    throw new HttpException(403, __('admin_ip_blocked'));
                }
                if (App::setting('enforce_admin_2fa', false) === true && (int) $user['totp_enabled'] !== 1 && $request->path !== '/client/security') {
                    flash('warning', __('admin_2fa_required'));

                    return Response::redirect(url('/client/security'));
                }

                return $next($request);
            },
        ];
    }

    public static function handle(Request $request): Response
    {
        $response = self::dispatch($request);
        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; connect-src 'self' wss:" . self::plainWebsocketSource() . "; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        ];
        if (request_is_https()) {
            $response->headers += ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'];
        }

        return $response;
    }

    /**
     * The console websocket of a Pterodactyl panel served over plain HTTP is
     * ws:// rather than wss://; allow it only in that case.
     */
    private static function plainWebsocketSource(): string
    {
        $panel = (string) config('pterodactyl.url', '');

        return str_starts_with(strtolower($panel), 'http://') ? ' ws:' : '';
    }

    private static function dispatch(Request $request): Response
    {
        try {
            if (!App::isInstalled()) {
                $allowed = str_starts_with($request->path, '/install') || str_starts_with($request->path, '/assets');
                if (!$allowed) {
                    return Response::redirect(url('/install'));
                }
            }
            self::applyLocale();
            if (App::setting('maintenance_mode', false) === true && !Auth::isAdmin()) {
                return Response::view('errors/maintenance', [], 503);
            }

            return App::router()->dispatch($request);
        } catch (HttpException $e) {
            return self::error($e->status, $e->getMessage(), $request);
        } catch (\Throwable $e) {
            Logger::error('http.unhandled_exception', [
                'error' => $e->getMessage(),
                'where' => $e->getFile() . ':' . $e->getLine(),
                'path' => $request->path,
            ]);
            $message = (bool) config('app.debug', false) ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : '';

            return self::error(500, $message, $request);
        }
    }

    public static function error(int $status, string $message, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message !== '' ? $message : 'HTTP ' . $status], $status);
        }
        try {
            return Response::view('errors/error', [
                'status' => $status,
                'message' => $message,
            ], $status);
        } catch (\Throwable) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>' . $status . '</h1><p>' . e($message) . '</p>', $status);
        }
    }

    private static function applyLocale(): void
    {
        $user = Auth::user();
        $locale = Session::instance()->get('locale');
        if (!is_string($locale) || $locale === '') {
            $locale = $user['locale'] ?? (string) config('app.locale', 'en');
        }
        Translator::setLocale((string) $locale);
    }

    private static function ipAllowed(string $ip): bool
    {
        $list = trim((string) config('security.admin_ip_allowlist', ''));
        if ($list === '') {
            return true;
        }
        foreach (preg_split('/[\s,]+/', $list) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                [$subnet, $bits] = explode('/', $entry, 2);
                if (self::inCidr($ip, $subnet, (int) $bits)) {
                    return true;
                }
            } elseif ($entry === $ip) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ip, string $subnet, int $bits): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
