<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Hasher;
use App\Core\Mailer;
use App\Core\Queue;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Token;
use App\Domain\Cart;
use App\Domain\UserRepository;

final class AuthController
{
    public function loginForm(Request $request, array $params): Response
    {
        return Response::view('auth/login', ['next' => $request->string('next'), 'cartCount' => Cart::count()]);
    }

    public function login(Request $request, array $params): Response
    {
        $email = $request->string('email');
        $password = $request->string('password');
        $next = $request->string('next');
        $limiterKey = 'login:' . $request->ip() . ':' . mb_strtolower($email);
        if (!RateLimiter::attempt($limiterKey, 10, 300)) {
            flash('error', __('too_many_attempts'));

            return Response::redirect(url('/login'));
        }
        $result = Auth::attempt($email, $password);
        switch ($result['status']) {
            case 'ok':
                Cart::attachUser((int) $result['user']['id']);
                flash('success', __('welcome_back'));

                return Response::redirect($this->safeNext($next));
            case '2fa':
                return Response::redirect(url('/login/2fa?next=' . rawurlencode($next)));
            case 'locked':
                flash('error', __('account_locked'));
                break;
            case 'blocked':
                flash('error', __('account_blocked'));
                break;
            case 'disabled':
                flash('error', __('account_disabled'));
                break;
            default:
                flash('error', __('invalid_credentials'));
        }
        Session::instance()->flashInput(['email' => $email]);

        return Response::redirect(url('/login'));
    }

    public function twoFactorForm(Request $request, array $params): Response
    {
        if (!is_array(Session::instance()->get('_2fa_pending'))) {
            return Response::redirect(url('/login'));
        }

        return Response::view('auth/two-factor', ['next' => $request->string('next')]);
    }

    public function twoFactor(Request $request, array $params): Response
    {
        if (!Auth::completeTwoFactor($request->string('code'))) {
            flash('error', __('invalid_2fa_code'));

            return Response::redirect(url('/login/2fa'));
        }
        flash('success', __('welcome_back'));

        return Response::redirect($this->safeNext($request->string('next')));
    }

    public function logout(Request $request, array $params): Response
    {
        Auth::logout();
        flash('success', __('logged_out'));

        return Response::redirect(url('/'));
    }

    public function registerForm(Request $request, array $params): Response
    {
        return Response::view('auth/register', ['next' => $request->string('next'), 'cartCount' => Cart::count()]);
    }

    public function register(Request $request, array $params): Response
    {
        if (!RateLimiter::attempt('register:' . $request->ip(), 5, 3600)) {
            flash('error', __('too_many_attempts'));

            return Response::redirect(url('/register'));
        }
        $username = $request->string('username');
        $email = mb_strtolower($request->string('email'));
        $password = $request->string('password');
        $errors = [];
        if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,32}$/', $username)) {
            $errors[] = __('username_invalid');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('email_invalid');
        }
        if (strlen($password) < 10) {
            $errors[] = __('password_too_short');
        }
        if (!$request->bool('terms')) {
            $errors[] = __('must_accept_terms');
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            Session::instance()->flashInput(['username' => $username, 'email' => $email]);

            return Response::redirect(url('/register'));
        }
        if (UserRepository::findByEmail($email) !== null) {
            flash('error', __('email_taken'));

            return Response::redirect(url('/register'));
        }

        $user = UserRepository::create($username, $email, $password, [
            'country' => $request->string('country', 'IT'),
            'locale' => Session::instance()->get('locale', config('app.locale')),
        ]);
        $this->recordAcceptance((int) $user['id'], $request->ip());
        $this->sendVerification($user, $email);
        Auth::login($user);
        Cart::attachUser((int) $user['id']);
        flash('success', __('registration_complete'));

        return Response::redirect($this->safeNext($request->string('next')));
    }

    public function verifyEmail(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $row = App::db()->selectOne(
            'SELECT * FROM email_verifications WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1',
            ['hash' => Token::hash($token), 'now' => Database::now()]
        );
        if ($row === null) {
            flash('error', __('verification_invalid'));

            return Response::redirect(url('/client'));
        }
        App::db()->update('email_verifications', ['used_at' => Database::now()], 'id = :id', ['id' => (int) $row['id']]);
        App::db()->update('users', ['email_verified_at' => Database::now()], 'id = :id', ['id' => (int) $row['user_id']]);
        Audit::log('auth.email_verified', 'user', (int) $row['user_id']);
        flash('success', __('email_verified'));

        return Response::redirect(Auth::check() ? url('/client') : url('/login'));
    }

    public function forgotForm(Request $request, array $params): Response
    {
        return Response::view('auth/forgot', []);
    }

    public function sendReset(Request $request, array $params): Response
    {
        $email = $request->string('email');
        if (!RateLimiter::attempt('reset:' . $request->ip(), 5, 900)) {
            flash('error', __('too_many_attempts'));

            return Response::redirect(url('/password/forgot'));
        }
        $user = UserRepository::findByEmail($email);
        if ($user !== null) {
            $token = Token::generate(32);
            App::db()->insert('password_resets', [
                'user_id' => (int) $user['id'],
                'token_hash' => Token::hash($token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + (int) config('security.reset_token_ttl', 3600)),
                'created_at' => Database::now(),
            ]);
            $link = url('/password/reset/' . $token);
            Queue::push(\App\Domain\Jobs\SendMailJob::class, [
                'to' => Crypto::encrypt($email),
                'subject' => __('mail_reset_subject'),
                'html' => Mailer::template(__('mail_reset_heading'), '<p>' . __('mail_reset_body') . '</p>', ['cta_url' => $link, 'cta_label' => __('reset_password')]),
                'template' => 'password_reset',
            ]);
        }
        flash('success', __('reset_email_sent'));

        return Response::redirect(url('/login'));
    }

    public function resetForm(Request $request, array $params): Response
    {
        return Response::view('auth/reset', ['token' => (string) ($params['token'] ?? '')]);
    }

    public function reset(Request $request, array $params): Response
    {
        $token = $request->string('token');
        $password = $request->string('password');
        if (strlen($password) < 10) {
            flash('error', __('password_too_short'));

            return Response::redirect(url('/password/reset/' . rawurlencode($token)));
        }
        $row = App::db()->selectOne(
            'SELECT * FROM password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1',
            ['hash' => Token::hash($token), 'now' => Database::now()]
        );
        if ($row === null) {
            flash('error', __('reset_token_invalid'));

            return Response::redirect(url('/password/forgot'));
        }
        App::db()->update('password_resets', ['used_at' => Database::now()], 'id = :id', ['id' => (int) $row['id']]);
        App::db()->update('users', ['password_hash' => Hasher::hash($password), 'locked_until' => null, 'failed_logins' => 0], 'id = :id', ['id' => (int) $row['user_id']]);
        App::db()->execute('UPDATE user_sessions SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL', ['now' => Database::now(), 'user' => (int) $row['user_id']]);
        Audit::log('auth.password_reset', 'user', (int) $row['user_id']);
        flash('success', __('password_updated'));

        return Response::redirect(url('/login'));
    }

    private function sendVerification(array $user, string $email): void
    {
        $token = Token::generate(32);
        App::db()->insert('email_verifications', [
            'user_id' => (int) $user['id'],
            'token_hash' => Token::hash($token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (int) config('security.verify_token_ttl', 86400)),
            'created_at' => Database::now(),
        ]);
        Queue::push(\App\Domain\Jobs\SendMailJob::class, [
            'to' => Crypto::encrypt($email),
            'subject' => __('mail_verify_subject'),
            'html' => Mailer::template(__('mail_verify_heading'), '<p>' . __('mail_verify_body') . '</p>', [
                'cta_url' => url('/verify-email/' . $token),
                'cta_label' => __('verify_email'),
            ]),
            'template' => 'email_verification',
        ]);
    }

    private function recordAcceptance(int $userId, string $ip): void
    {
        $version = (string) App::setting('legal_version', '2026-01');
        foreach (['terms', 'privacy'] as $document) {
            App::db()->insert('legal_acceptances', [
                'user_id' => $userId,
                'document' => $document,
                'version' => $version,
                'ip' => $ip,
                'accepted_at' => Database::now(),
            ]);
        }
    }

    private function safeNext(string $next): string
    {
        if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            return url($next);
        }

        return url('/client');
    }
}
