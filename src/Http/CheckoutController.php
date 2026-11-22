<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Cart;
use App\Domain\GatewayRegistry;
use App\Domain\InvoiceService;
use App\Domain\LicenseClient;
use App\Domain\OrderService;
use App\Domain\PaymentService;
use App\Domain\UserRepository;
use App\Domain\WalletService;

final class CheckoutController
{
    public function show(Request $request, array $params): Response
    {
        $items = Cart::items();
        if ($items === []) {
            return Response::redirect(url('/cart'));
        }
        $user = Auth::user() ?? [];

        return Response::view('checkout', [
            'items' => $items,
            'total' => Cart::total($items),
            'gateways' => GatewayRegistry::usable(),
            'profile' => UserRepository::present($user),
            'wallet' => WalletService::balance((int) $user['id']),
            'cartCount' => count($items),
            'licenseOk' => LicenseClient::allowsSales(),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        $items = Cart::items();
        if ($items === []) {
            return Response::redirect(url('/cart'));
        }
        if (!LicenseClient::allowsSales()) {
            flash('error', __('license_sales_blocked'));

            return Response::redirect(url('/cart'));
        }
        if (!$request->bool('terms')) {
            flash('error', __('must_accept_terms'));

            return Response::redirect(url('/checkout'));
        }
        $user = Auth::user() ?? [];
        try {
            $created = OrderService::create($user, $items, $request->string('coupon'), [
                'ip' => $request->ip(),
                'email' => UserRepository::present($user)['email'] ?? '',
                'user_agent' => $request->userAgent(),
            ]);
        } catch (HttpException $e) {
            flash('error', $e->getMessage());

            return Response::redirect(url('/checkout'));
        }
        Cart::markConverted();
        Cart::clear();
        if (($created['order']['status'] ?? '') === 'review') {
            flash('warning', __('order_in_review'));

            return Response::redirect(url('/client'));
        }

        return Response::redirect(url('/checkout/pay/' . (int) $created['order']['id']));
    }

    public function pay(Request $request, array $params): Response
    {
        $order = $this->orderOrFail((int) ($params['id'] ?? 0));
        if (in_array((string) $order['status'], ['paid', 'provisioning', 'active'], true)) {
            return Response::redirect(url('/checkout/return/' . (int) $order['id']));
        }

        return Response::view('pay', [
            'order' => $order,
            'invoice' => InvoiceService::find((int) $order['invoice_id']),
            'gateways' => GatewayRegistry::usable(),
            'wallet' => WalletService::balance((int) $order['user_id']),
            'instructions' => (string) $request->session()->pull('payment_instructions', ''),
            'cartCount' => Cart::count(),
        ]);
    }

    public function startPayment(Request $request, array $params): Response
    {
        $order = $this->orderOrFail((int) ($params['id'] ?? 0));
        if (in_array((string) $order['status'], ['paid', 'provisioning', 'active'], true)) {
            return Response::redirect(url('/checkout/return/' . (int) $order['id']));
        }
        $gateway = GatewayRegistry::get($request->string('gateway'));
        if (!$gateway->isConfigured()) {
            flash('error', __('payment_method_unavailable'));

            return Response::redirect(url('/checkout/pay/' . (int) $order['id']));
        }
        $order['customer_email'] = UserRepository::present(UserRepository::find((int) $order['user_id']) ?? [])['email'] ?? '';
        $invoice = InvoiceService::find((int) $order['invoice_id']) ?? [];
        try {
            $result = $gateway->start($order, $invoice, PaymentService::returnUrl($order));
        } catch (HttpException $e) {
            flash('error', $e->getMessage());

            return Response::redirect(url('/checkout/pay/' . (int) $order['id']));
        } catch (\Throwable $e) {
            \App\Core\Logger::error('checkout.gateway_error', ['gateway' => $gateway->code(), 'error' => $e->getMessage()]);
            flash('error', __('payment_start_failed'));

            return Response::redirect(url('/checkout/pay/' . (int) $order['id']));
        }

        if (($result['type'] ?? '') === 'redirect') {
            return Response::redirect((string) $result['url']);
        }
        if (($result['type'] ?? '') === 'paid') {
            flash('success', __('payment_completed'));

            return Response::redirect((string) ($result['url'] ?? url('/checkout/return/' . (int) $order['id'])));
        }
        if (($result['type'] ?? '') === 'instructions') {
            $request->session()->put('payment_instructions', (string) ($result['message'] ?? ''));

            return Response::redirect(url('/checkout/pay/' . (int) $order['id']));
        }

        return Response::redirect(url('/checkout/pay/' . (int) $order['id']));
    }

    public function returnFromGateway(Request $request, array $params): Response
    {
        $order = $this->orderOrFail((int) ($params['id'] ?? 0));

        return Response::view('payment-return', [
            'order' => $order,
            'invoice' => InvoiceService::find((int) $order['invoice_id']),
            'status' => $request->string('status', 'pending'),
            'cartCount' => Cart::count(),
        ]);
    }

    /** @return array<string,mixed> */
    private function orderOrFail(int $id): array
    {
        $order = App::db()->selectOne(
            'SELECT * FROM orders WHERE id = :id AND user_id = :user LIMIT 1',
            ['id' => $id, 'user' => (int) Auth::id()]
        );
        if ($order === null) {
            throw new HttpException(404);
        }

        return $order;
    }
}
