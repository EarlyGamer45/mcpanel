<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper with rotation, flash messages and old input support.
 */
final class Session
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    /** @param array{name?:string,secure?:bool,lifetime?:int,samesite?:string} $config */
    public static function start(array $config = []): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (session_status() === PHP_SESSION_NONE) {
            $secure = (bool) ($config['secure'] ?? false);
            session_name((string) ($config['name'] ?? 'mch_session'));
            session_set_cookie_params([
                'lifetime' => (int) ($config['lifetime'] ?? 7200),
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => (string) ($config['samesite'] ?? 'Lax'),
            ]);
            session_start();
        }
        self::$instance = new self();

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
        }

        return self::$instance;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /** @return array<string,list<string>> */
    public function flashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($flashes) ? $flashes : [];
    }

    /** @param array<string,mixed> $input */
    public function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $_SESSION['_old'] = $input;
    }

    public function old(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_old'][$key] ?? $default;
    }

    public function clearOld(): void
    {
        unset($_SESSION['_old']);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function id(): string
    {
        return (string) session_id();
    }
}

final class Csrf
{
    public static function token(): string
    {
        $session = Session::instance();
        $token = $session->get('_csrf');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $session->put('_csrf', $token);
        }

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verify(Request $request): void
    {
        $expected = Session::instance()->get('_csrf');
        $provided = (string) ($request->input('_token') ?? $request->header('X-CSRF-Token') ?? '');
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
            Logger::warning('security.csrf_rejected', ['path' => $request->path, 'ip' => $request->ip()]);
            throw new HttpException(419, 'CSRF token mismatch — reload the page and try again.');
        }
    }
}

final class Auth
{
    public const ADMIN_ROLES = ['super_admin', 'admin', 'support', 'billing'];

    /** @var array<string,list<string>> */
    private const PERMISSIONS = [
        'super_admin' => ['*'],
        'admin' => ['catalog', 'clients', 'services', 'orders', 'invoices', 'tickets', 'settings', 'pterodactyl', 'reports', 'logs', 'licence'],
        'support' => ['tickets', 'clients.view', 'services.view'],
        'billing' => ['invoices', 'orders', 'reports', 'refunds'],
        'customer' => [],
    ];

    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $pending = Session::instance()->get('_2fa_pending');
        if (is_array($pending)) {
            self::$user = $pending;

            return self::$user;
        }
        $id = Session::instance()->get('_user_id');
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        $row = App::db()->selectOne('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1', ['id' => (int) $id]);
        $revoked = $row !== null && App::db()->value(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = :user AND session_id = :sid AND revoked_at IS NOT NULL',
            ['user' => (int) $row['id'], 'sid' => Session::instance()->id()]
        );
        if ($row === null || ($row['status'] ?? '') !== 'active' || (int) $revoked > 0) {
            // Blocked, disabled or signed out elsewhere (password change, admin action).
            Session::instance()->forget('_user_id');

            return null;
        }
        self::$user = $row;

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null && !Session::instance()->has('_2fa_pending');
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && in_array((string) $user['role'], self::ADMIN_ROLES, true);
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        $role = (string) $user['role'];
        $granted = self::PERMISSIONS[$role] ?? [];
        if (in_array('*', $granted, true) || in_array($permission, $granted, true)) {
            return true;
        }
        foreach ($granted as $candidate) {
            if (str_ends_with($candidate, '.*') && str_starts_with($permission, rtrim($candidate, '.*') . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status:string,user?:array<string,mixed>}
     * status: ok | 2fa | invalid | locked | blocked | disabled
     */
    public static function attempt(string $email, string $password): array
    {
        $db = App::db();
        $identifier = trim($email);
        // Sign in with the e-mail address or with the username.
        $row = str_contains($identifier, '@')
            ? $db->selectOne(
                'SELECT * FROM users WHERE email_index = :index AND deleted_at IS NULL LIMIT 1',
                ['index' => BlindIndex::email($identifier)]
            )
            : $db->selectOne(
                "SELECT * FROM users WHERE LOWER(username) = :username AND deleted_at IS NULL AND status <> 'deleted' LIMIT 1",
                ['username' => mb_strtolower($identifier)]
            );
        if ($row === null) {
            UsernameDenier::burnTime($password);

            return ['status' => 'invalid'];
        }
        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until'] . ' UTC') > time()) {
            return ['status' => 'locked'];
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            self::registerFailure($row);

            return ['status' => 'invalid'];
        }
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_ARGON2ID)) {
            $db->update('users', ['password_hash' => Hasher::hash($password)], 'id = :id', ['id' => (int) $row['id']]);
        }
        if ((string) $row['status'] === 'blocked') {
            return ['status' => 'blocked'];
        }
        if ((string) $row['status'] !== 'active') {
            return ['status' => 'disabled'];
        }
        if ((int) $row['totp_enabled'] === 1) {
            Session::instance()->put('_2fa_pending', $row);

            return ['status' => '2fa'];
        }
        self::login($row);

        return ['status' => 'ok', 'user' => $row];
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        $session = Session::instance();
        $session->regenerate();
        $session->put('_user_id', (int) $user['id']);
        $session->forget('_2fa_pending');
        $session->forget('_csrf');
        self::$user = $user;
        self::$resolved = true;

        App::db()->update('users', [
            'last_login_at' => Database::now(),
            'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'failed_logins' => 0,
            'locked_until' => null,
        ], 'id = :id', ['id' => (int) $user['id']]);

        App::db()->insert('user_sessions', [
            'user_id' => (int) $user['id'],
            'session_id' => $session->id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => Str::limit((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 250),
            'last_seen_at' => Database::now(),
        ]);

        Audit::log('auth.login', 'user', (int) $user['id']);
    }

    public static function completeTwoFactor(string $code): bool
    {
        $pending = Session::instance()->get('_2fa_pending');
        if (!is_array($pending)) {
            return false;
        }
        $secret = Crypto::decrypt((string) ($pending['totp_secret_enc'] ?? ''));
        if ($secret !== null && Totp::verify($secret, $code)) {
            self::login($pending);

            return true;
        }
        $codes = Json::decode(Crypto::decrypt((string) ($pending['recovery_codes_enc'] ?? '')) ?: '[]');
        foreach ($codes as $index => $candidate) {
            if (hash_equals((string) $candidate, strtoupper(trim($code)))) {
                unset($codes[$index]);
                App::db()->update('users', [
                    'recovery_codes_enc' => Crypto::encrypt(Json::encode(array_values($codes))),
                ], 'id = :id', ['id' => (int) $pending['id']]);
                self::login($pending);
                Audit::log('auth.recovery_code_used', 'user', (int) $pending['id']);

                return true;
            }
        }

        return false;
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id !== null) {
            App::db()->execute(
                'UPDATE user_sessions SET revoked_at = :now WHERE session_id = :sid',
                ['now' => Database::now(), 'sid' => Session::instance()->id()]
            );
            Audit::log('auth.logout', 'user', $id);
        }
        Session::instance()->destroy();
        self::$user = null;
        self::$resolved = false;
    }

    public static function refresh(): void
    {
        self::$resolved = false;
        self::$user = null;
        self::user();
    }

    /** @param array<string,mixed> $row */
    private static function registerFailure(array $row): void
    {
        $db = App::db();
        $attempts = (int) $row['failed_logins'] + 1;
        $max = (int) Config::get('security.max_login_attempts', 5);
        $data = ['failed_logins' => $attempts];
        if ($attempts >= $max) {
            $data['locked_until'] = gmdate('Y-m-d H:i:s', time() + (int) Config::get('security.lockout_minutes', 15) * 60);
            $data['failed_logins'] = 0;
            Audit::log('auth.locked', 'user', (int) $row['id']);
        }
        $db->update('users', $data, 'id = :id', ['id' => (int) $row['id']]);
        Audit::log('auth.failed', 'user', (int) $row['id']);
    }
}

/** Constant-ish work for unknown accounts, to blur user enumeration timing. */
final class UsernameDenier
{
    public static function burnTime(string $password): void
    {
        $dummy = '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlkdW1teWR1bW15$' . base64_encode(str_repeat("\0", 32));
        @password_verify($password, $dummy);
    }
}

final class Hasher
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MiB
            'time_cost' => 4,
            'threads' => 1,
        ]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

/** Simple file based limiter for public endpoints (login, register, api). */
final class RateLimiter
{
    public static function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $file = App::path('storage/cache/ratelimit/' . hash('sha256', $key) . '.json');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle) ?: '[]';
        $hits = array_values(array_filter(Json::decode($raw), static fn ($t) => is_int($t) && $t > time() - $windowSeconds));
        $allowed = count($hits) < $maxAttempts;
        if ($allowed) {
            $hits[] = time();
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, Json::encode($hits));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $allowed;
    }
}

final class Audit
{
    /** @param array<string,mixed> $meta */
    public static function log(string $action, ?string $subjectType = null, ?int $subjectId = null, array $meta = []): void
    {
        try {
            $user = Auth::user();
            App::db()->insert('audit_logs', [
                'actor_id' => $user === null ? null : (int) $user['id'],
                'actor_type' => $user === null ? 'system' : 'user',
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'meta_json' => Json::encode($meta),
                'created_at' => Database::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('audit.write_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
