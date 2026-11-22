<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Auth;
use App\Core\Audit;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Json;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;
use App\Domain\Attachments;
use App\Domain\Cart;
use App\Domain\Catalog;
use App\Domain\InvoiceService;
use App\Domain\Services;
use App\Domain\TicketService;
use App\Domain\UserRepository;
use App\Domain\WalletService;
use App\Domain\Pterodactyl\ClientClient;

final class ClientController
{
    public function dashboard(Request $request, array $params): Response
    {
        $user = Auth::user() ?? [];
        $userId = (int) $user['id'];
        $recentInvoices = App::db()->select('SELECT * FROM invoices WHERE user_id = :user ORDER BY id DESC LIMIT 5', ['user' => $userId]);
        $announcements = App::db()->select('SELECT * FROM announcements WHERE visible = 1 ORDER BY id DESC LIMIT 2');

        return Response::view('client/dashboard', [
            'services' => Services::forUser($userId),
            'stats' => UserRepository::dashboardStats($userId),
            'profile' => UserRepository::present($user),
            'invoices' => $recentInvoices,
            'announcements' => $announcements,
            'verified' => !empty($user['email_verified_at']),
            'cartCount' => Cart::count(),
        ]);
    }

    public function service(Request $request, array $params): Response
    {
        $service = $this->serviceOrFail((int) ($params['id'] ?? 0));
        $resources = null;
        if ($this->panelConfigured() && !empty($service['external_identifier'])) {
            try {
                $resources = $this->clientApi()->resources((string) $service['external_identifier']);
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('client.resources_failed', ['service' => (int) $service['id'], 'error' => $e->getMessage()]);
            }
        }

        return Response::view('client/service', [
            'service' => $service,
            'product' => Catalog::productById((int) $service['product_id']),
            'resources' => $resources,
            'events' => App::db()->select('SELECT * FROM service_events WHERE service_id = :id ORDER BY id DESC LIMIT 10', ['id' => (int) $service['id']]),
            'cartCount' => Cart::count(),
        ]);
    }

    public function power(Request $request, array $params): Response
    {
        $service = $this->serviceOrFail((int) ($params['id'] ?? 0));
        $signal = $request->string('signal');
        if (!$this->panelConfigured() || empty($service['external_identifier'])) {
            flash('error', __('panel_not_configured'));

            return Response::redirect(url('/client/service/' . (int) $service['id']));
        }
        try {
            $this->clientApi()->power((string) $service['external_identifier'], $signal);
            Audit::log('service.power', 'service', (int) $service['id'], ['signal' => $signal]);
            flash('success', __('power_command_sent', ['signal' => $signal]));
        } catch (\Throwable $e) {
            flash('error', __('power_command_failed') . ' ' . $e->getMessage());
        }

        return Response::redirect(url('/client/service/' . (int) $service['id']));
    }

    public function consoleToken(Request $request, array $params): Response
    {
        $service = $this->serviceOrFail((int) ($params['id'] ?? 0));
        if (!$this->panelConfigured() || empty($service['external_identifier'])) {
            return new Response(Json::encode(['error' => __('console_unavailable')]), 503, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        try {
            $credentials = $this->clientApi()->websocket((string) $service['external_identifier']);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('client.console_token_failed', ['service' => (int) $service['id'], 'error' => $e->getMessage()]);

            return new Response(Json::encode(['error' => __('console_unavailable')]), 502, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        $socket = (string) ($credentials['socket'] ?? '');
        $token = (string) ($credentials['token'] ?? '');
        if ($socket === '' || $token === '' || !str_starts_with($socket, 'wss://')) {
            return new Response(Json::encode(['error' => __('console_unavailable')]), 502, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        Audit::log('service.console_token', 'service', (int) $service['id']);

        return new Response(Json::encode(['socket' => $socket, 'token' => $token]), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $service = $this->serviceOrFail((int) ($params['id'] ?? 0));
        $reason = $request->string('reason');
        App::db()->update('services', [
            'auto_renew' => 0,
            'cancellation_at' => Database::now(),
            'updated_at' => Database::now(),
        ], 'id = :id', ['id' => (int) $service['id']]);
        Services::recordEvent((int) $service['id'], (string) $service['status'], (string) $service['status'], 'cancellation requested: ' . $reason, Auth::id());
        flash('success', __('cancellation_registered'));

        return Response::redirect(url('/client/service/' . (int) $service['id']));
    }

    public function invoices(Request $request, array $params): Response
    {
        $invoices = App::db()->select('SELECT * FROM invoices WHERE user_id = :user ORDER BY id DESC LIMIT 100', ['user' => (int) Auth::id()]);

        return Response::view('client/invoices', ['invoices' => $invoices, 'cartCount' => Cart::count()]);
    }

    public function invoicePdf(Request $request, array $params): Response
    {
        $invoice = $this->invoiceOrFail((int) ($params['id'] ?? 0));
        $path = InvoiceService::pdf((int) $invoice['id']);
        $body = (string) file_get_contents($path);

        return new Response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $invoice['number'] . '.pdf"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function payInvoice(Request $request, array $params): Response
    {
        $invoice = $this->invoiceOrFail((int) ($params['id'] ?? 0));
        if ($invoice['order_id'] === null) {
            flash('error', __('invoice_without_order'));

            return Response::redirect(url('/client/invoices'));
        }

        return Response::redirect(url('/checkout/pay/' . (int) $invoice['order_id']));
    }

    public function wallet(Request $request, array $params): Response
    {
        $userId = (int) Auth::id();
        $topups = App::db()->select('SELECT * FROM wallet_topups WHERE user_id = :user ORDER BY id DESC LIMIT 20', ['user' => $userId]);

        return Response::view('client/wallet', [
            'balance' => WalletService::balance($userId),
            'transactions' => WalletService::transactions($userId),
            'topups' => $topups,
            'bank' => (array) config('payments.bank', []),
            'cartCount' => Cart::count(),
        ]);
    }

    public function topUp(Request $request, array $params): Response
    {
        $amount = $request->int('amount_cents') > 0
            ? $request->int('amount_cents')
            : (\App\Core\Money::parseInput($request->string('amount')) ?? 1000);
        $amount = max(500, min(50000, $amount));
        $reference = 'WT-' . Auth::id() . '-' . strtoupper(bin2hex(random_bytes(3)));
        App::db()->insert('wallet_topups', [
            'user_id' => (int) Auth::id(),
            'amount_cents' => $amount,
            'reference' => $reference,
            'status' => 'pending',
            'created_at' => Database::now(),
        ]);
        flash('success', __('topup_created', ['reference' => $reference]));

        return Response::redirect(url('/client/wallet'));
    }

    public function tickets(Request $request, array $params): Response
    {
        return Response::view('client/tickets', [
            'tickets' => TicketService::forUser((int) Auth::id()),
            'services' => Services::forUser((int) Auth::id()),
            'cartCount' => Cart::count(),
        ]);
    }

    public function openTicket(Request $request, array $params): Response
    {
        $subject = $request->string('subject');
        $body = $request->string('body');
        if ($subject === '' || $body === '') {
            flash('error', __('ticket_required_fields'));

            return Response::redirect(url('/client/tickets'));
        }
        $ticket = TicketService::open(
            (int) Auth::id(),
            $request->string('department', 'technical'),
            $subject,
            $body,
            $request->int('service_id') ?: null,
            $request->string('priority', 'normal')
        );
        self::storeAttachment($request, (int) $ticket['id']);
        flash('success', __('ticket_created', ['number' => (string) $ticket['number']]));

        return Response::redirect(url('/client/ticket/' . (int) $ticket['id']));
    }

    public function ticket(Request $request, array $params): Response
    {
        $ticket = TicketService::findForUser((int) ($params['id'] ?? 0), (int) Auth::id());
        if ($ticket === null) {
            throw new HttpException(404);
        }

        return Response::view('client/ticket', [
            'ticket' => $ticket,
            'messages' => TicketService::messages((int) $ticket['id']),
            'attachments' => Attachments::forTicket((int) $ticket['id']),
            'cartCount' => Cart::count(),
        ]);
    }

    public function replyTicket(Request $request, array $params): Response
    {
        $ticket = TicketService::findForUser((int) ($params['id'] ?? 0), (int) Auth::id());
        if ($ticket === null) {
            throw new HttpException(404);
        }
        $body = $request->string('body');
        if ($body !== '') {
            TicketService::reply((int) $ticket['id'], (int) Auth::id(), $body);
            self::storeAttachment($request, (int) $ticket['id']);
            flash('success', __('reply_sent'));
        }

        return Response::redirect(url('/client/ticket/' . (int) $ticket['id']));
    }

    public function downloadAttachment(Request $request, array $params): Response
    {
        $attachment = Attachments::findForUser((int) ($params['id'] ?? 0), (int) Auth::id());
        if ($attachment === null) {
            throw new HttpException(404);
        }
        $path = Attachments::absolutePath($attachment);
        if (!is_file($path)) {
            throw new HttpException(404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => (string) $attachment['mime'],
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . addcslashes((string) $attachment['filename'], "\"\\") . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Stores the optional attachment on the ticket's newest message. */
    private static function storeAttachment(Request $request, int $ticketId): void
    {
        $file = $request->file('attachment');
        if ($file === []) {
            return;
        }
        try {
            Attachments::store($ticketId, TicketService::latestMessageId($ticketId), $file);
        } catch (HttpException $e) {
            flash('warning', $e->getMessage());
        }
    }

    public function profile(Request $request, array $params): Response
    {
        return Response::view('client/profile', [
            'profile' => UserRepository::present(Auth::user() ?? []),
            'cartCount' => Cart::count(),
        ]);
    }

    public function updateProfile(Request $request, array $params): Response
    {
        $username = trim($request->string('username', (string) (Auth::user()['username'] ?? '')));
        if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,32}$/', $username)) {
            flash('error', __('username_invalid'));

            return Response::redirect(url('/client/profile'));
        }
        if (App::db()->selectOne('SELECT id FROM users WHERE LOWER(username) = :u AND id <> :id', ['u' => mb_strtolower($username), 'id' => (int) Auth::id()]) !== null) {
            flash('error', __('username_taken'));

            return Response::redirect(url('/client/profile'));
        }
        UserRepository::updateProfile((int) Auth::id(), [
            'username' => $username,
            'country' => strtoupper($request->string('country', 'IT')),
            'company' => $request->string('company'),
            'vat' => $request->string('vat'),
            'address' => $request->string('address'),
            'city' => $request->string('city'),
            'postal_code' => $request->string('postal_code'),
            'phone' => $request->string('phone'),
        ]);
        Audit::log('profile.updated', 'user', (int) Auth::id());
        flash('success', __('profile_updated'));

        return Response::redirect(url('/client/profile'));
    }

    public function security(Request $request, array $params): Response
    {
        $user = Auth::user() ?? [];
        $secret = Session::instance()->get('_totp_setup');
        if (!is_string($secret) || $secret === '') {
            $secret = Totp::secret();
            Session::instance()->put('_totp_setup', $secret);
        }
        $sessions = App::db()->select(
            'SELECT * FROM user_sessions WHERE user_id = :user AND revoked_at IS NULL ORDER BY id DESC LIMIT 10',
            ['user' => (int) $user['id']]
        );

        return Response::view('client/security', [
            'secret' => $secret,
            'otpauth' => Totp::uri($secret, (string) $user['username'], (string) config('app.name')),
            'enabled' => (int) $user['totp_enabled'] === 1,
            'recoveryCodes' => Session::instance()->pull('_recovery_codes', []),
            'sessions' => $sessions,
            'cartCount' => Cart::count(),
        ]);
    }

    public function enableTwoFactor(Request $request, array $params): Response
    {
        $secret = Session::instance()->get('_totp_setup');
        if (!is_string($secret) || !Totp::verify($secret, $request->string('code'))) {
            flash('error', __('invalid_2fa_code'));

            return Response::redirect(url('/client/security'));
        }
        $codes = Totp::recoveryCodes();
        App::db()->update('users', [
            'totp_secret_enc' => Crypto::encrypt($secret),
            'totp_enabled' => 1,
            'recovery_codes_enc' => Crypto::encrypt(Json::encode($codes)),
        ], 'id = :id', ['id' => (int) Auth::id()]);
        Session::instance()->forget('_totp_setup');
        Session::instance()->put('_recovery_codes', $codes);
        Audit::log('security.2fa_enabled', 'user', (int) Auth::id());
        flash('success', __('2fa_enabled'));

        return Response::redirect(url('/client/security'));
    }

    /** Changes the sign-in e-mail after checking the current password. */
    public function changeEmail(Request $request, array $params): Response
    {
        $back = url('/client/security#email');
        $user = Auth::user() ?? [];
        if (!password_verify($request->string('current_password'), (string) ($user['password_hash'] ?? ''))) {
            Audit::log('auth.email_change_failed', 'user', (int) $user['id']);
            flash('error', __('current_password_wrong'));

            return Response::redirect($back);
        }
        try {
            UserRepository::changeEmail((int) $user['id'], $request->string('new_email'));
        } catch (\App\Core\HttpException $e) {
            flash('error', $e->getMessage());

            return Response::redirect($back);
        }
        Audit::log('auth.email_changed', 'user', (int) $user['id']);
        flash('success', __('email_changed'));

        return Response::redirect($back);
    }

    public function changePassword(Request $request, array $params): Response
    {
        $back = url('/client/security#password');
        $user = Auth::user() ?? [];
        $current = $request->string('current_password');
        $new = $request->string('new_password');
        $confirm = $request->string('new_password_confirmation');
        if (!password_verify($current, (string) ($user['password_hash'] ?? ''))) {
            Audit::log('auth.password_change_failed', 'user', (int) $user['id']);
            flash('error', __('current_password_wrong'));

            return Response::redirect($back);
        }
        if (strlen($new) < 10) {
            flash('error', __('password_too_short'));

            return Response::redirect($back);
        }
        if ($new !== $confirm) {
            flash('error', __('password_mismatch'));

            return Response::redirect($back);
        }
        if (hash_equals($current, $new)) {
            flash('error', __('password_same_as_old'));

            return Response::redirect($back);
        }
        UserRepository::setPassword((int) $user['id'], $new);
        // Keep this browser signed in, sign out every other device.
        UserRepository::revokeSessions((int) $user['id'], Session::instance()->id());
        Audit::log('auth.password_changed', 'user', (int) $user['id']);
        flash('success', __('password_changed'));

        return Response::redirect($back);
    }

    public function disableTwoFactor(Request $request, array $params): Response
    {
        App::db()->update('users', [
            'totp_enabled' => 0,
            'totp_secret_enc' => null,
            'recovery_codes_enc' => null,
        ], 'id = :id', ['id' => (int) Auth::id()]);
        Audit::log('security.2fa_disabled', 'user', (int) Auth::id());
        flash('success', __('2fa_disabled'));

        return Response::redirect(url('/client/security'));
    }

    public function exportData(Request $request, array $params): Response
    {
        $export = UserRepository::export((int) Auth::id());
        Audit::log('gdpr.export', 'user', (int) Auth::id());

        return Response::json($export)
            ->withHeader('Content-Disposition', 'attachment; filename="personal-data-' . Auth::id() . '.json"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function deleteAccount(Request $request, array $params): Response
    {
        if (!$request->bool('confirm')) {
            flash('error', __('gdpr_confirm_required'));

            return Response::redirect(url('/client/profile'));
        }
        $active = (int) App::db()->value(
            'SELECT COUNT(*) FROM services WHERE user_id = :user AND status IN (:active, :suspended)',
            ['user' => (int) Auth::id(), 'active' => 'active', 'suspended' => 'suspended']
        );
        if ($active > 0) {
            flash('error', __('gdpr_active_services'));

            return Response::redirect(url('/client/profile'));
        }
        $id = (int) Auth::id();
        Auth::logout();
        UserRepository::anonymise($id);
        flash('success', __('gdpr_deleted'));

        return Response::redirect(url('/'));
    }

    /** @return array<string,mixed> */
    private function serviceOrFail(int $id): array
    {
        $service = Services::findForUser($id, (int) Auth::id());
        if ($service === null) {
            throw new HttpException(404);
        }

        return $service;
    }

    /** @return array<string,mixed> */
    private function invoiceOrFail(int $id): array
    {
        $invoice = App::db()->selectOne('SELECT * FROM invoices WHERE id = :id AND user_id = :user', ['id' => $id, 'user' => (int) Auth::id()]);
        if ($invoice === null) {
            throw new HttpException(404);
        }

        return $invoice;
    }

    private function panelConfigured(): bool
    {
        return trim((string) config('pterodactyl.url', '')) !== '' && trim((string) config('pterodactyl.client_key', '')) !== '';
    }

    private function clientApi(): ClientClient
    {
        return new ClientClient((string) config('pterodactyl.url', ''), (string) config('pterodactyl.client_key', ''));
    }
}
