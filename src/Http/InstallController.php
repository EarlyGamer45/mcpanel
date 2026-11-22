<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Installer;
use App\Domain\UserRepository;

final class InstallController
{
    public function form(Request $request, array $params): Response
    {
        if (App::isInstalled()) {
            return Response::redirect(url('/login'));
        }
        $dbError = '';
        try {
            App::db()->value('SELECT 1');
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        return Response::view('install', [
            'keyMissing' => (string) config('app.key', '') === '',
            'blindIndexMissing' => (string) config('app.blind_index_key', '') === '',
            'dbError' => $dbError,
            'dbDriver' => (string) config('database.driver', 'mysql'),
        ]);
    }

    public function install(Request $request, array $params): Response
    {
        if (App::isInstalled()) {
            return Response::redirect(url('/login'));
        }
        if ((string) config('app.key', '') === '') {
            flash('error', __('install_missing_key'));

            return Response::redirect(url('/install'));
        }
        $username = $request->string('username');
        $email = mb_strtolower($request->string('email'));
        $password = $request->string('password');
        $siteName = $request->string('site_name', 'MCPanel Hosting');
        $company = $request->string('company_name', $siteName);
        $country = strtoupper($request->string('country', 'IT'));

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
        if ($errors !== []) {
            foreach ($errors as $error) {
                flash('error', $error);
            }

            return Response::redirect(url('/install'));
        }

        try {
            Installer::defaults();
            $user = Installer::admin($username, $email, $password);
            App::setSetting('brand_name', $siteName);
            App::setSetting('company_name', $company);
            App::setSetting('company_country', $country);
            App::setSetting('company_email', $email);
            App::setSetting('legal_version', gmdate('Y-m'));
            Logger::info('install.completed', ['admin' => (int) $user['id']]);
            Auth::login($user);
            Session::instance()->put('locale', (string) config('app.locale', 'en'));
            flash('success', __('install_complete'));

            return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/')));
        } catch (\Throwable $e) {
            Logger::error('install.failed', ['error' => $e->getMessage()]);
            flash('error', __('install_failed') . ' ' . $e->getMessage());

            return Response::redirect(url('/install'));
        }
    }
}
