<?php

declare(strict_types=1);

namespace App\Domain\Gateways;

use App\Core\ApiException;
use App\Core\HttpClient;
use App\Core\Request;
use App\Domain\PaymentGateway;

/**
 * PayPal Orders v2. Webhooks are not trusted on their own: every event causes a
 * fresh GET of the order against the PayPal API with our own credentials, and
 * only a COMPLETED order is accepted. A forged request cannot fake that.
 */
final class PayPalGateway implements PaymentGateway
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function code(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function description(): string
    {
        return __('gateway_paypal_desc');
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['client_id'] ?? '')) !== ''
            && trim((string) ($this->config['client_secret'] ?? '')) !== '';
    }

    public function supportsRecurring(): bool
    {
        return true;
    }

    public function start(array $order, array $invoice, string $returnUrl): array
    {
        $client = $this->api();
        $response = $client->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $order['number'],
                'custom_id' => (string) $order['id'],
                'invoice_id' => (string) $order['number'],
                'description' => 'Ordine ' . $order['number'],
                'amount' => [
                    'currency_code' => strtoupper((string) $order['currency']),
                    'value' => self::amount((int) $order['total_cents']),
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl . '?status=success',
                'cancel_url' => $returnUrl . '?status=cancelled',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ]);
        $json = $response->json();
        if (!$response->isOk()) {
            throw new ApiException($response->status, $json, 'PayPal order creation failed');
        }
        $approve = '';
        foreach ((array) ($json['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                $approve = (string) ($link['href'] ?? '');
            }
        }
        if ($approve === '') {
            throw new ApiException(502, $json, 'PayPal did not return an approval link');
        }

        return ['type' => 'redirect', 'url' => $approve];
    }

    public function handleWebhook(Request $request): ?array
    {
        $event = $request->json();
        $type = (string) ($event['event_type'] ?? '');
        if (!in_array($type, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'], true)) {
            return null;
        }
        $resource = (array) ($event['resource'] ?? []);
        $paypalOrderId = (string) ($resource['id'] ?? '');
        if ($paypalOrderId === '') {
            $paypalOrderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');
        }
        if ($paypalOrderId === '') {
            return null;
        }
        $order = $this->fetchOrder($paypalOrderId);
        if (($order['status'] ?? '') === 'APPROVED') {
            $this->api()->post('/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture');
            $order = $this->fetchOrder($paypalOrderId);
        }
        if (($order['status'] ?? '') !== 'COMPLETED') {
            return null;
        }
        $unit = (array) (($order['purchase_units'] ?? [])[0] ?? []);
        $amount = self::cents((string) ($unit['amount']['value'] ?? '0'));

        return [
            'event_id' => (string) ($event['id'] ?? $paypalOrderId . ':' . $type),
            'type' => $type,
            'status' => 'succeeded',
            'order_id' => (int) ($unit['custom_id'] ?? 0),
            'amount_cents' => $amount,
            'payment_id' => $paypalOrderId,
        ];
    }

    public function refund(array $payment, int $amountCents): void
    {
        $captureId = (string) ($payment['meta_json'] ?? '');
        $meta = $captureId === '' ? [] : (array) json_decode($captureId, true);
        $capture = (string) ($meta['capture_id'] ?? $payment['external_id'] ?? '');
        if ($capture === '') {
            throw new ApiException(422, [], 'No PayPal capture on this payment');
        }
        $response = $this->api()->post('/v2/payments/captures/' . rawurlencode($capture) . '/refund', [
            'amount' => [
                'value' => self::amount($amountCents),
                'currency_code' => (string) config('app.currency', 'EUR'),
            ],
        ]);
        if (!$response->isOk()) {
            throw new ApiException($response->status, $response->json(), 'PayPal refund failed');
        }
    }

    /** @return array<string,mixed> */
    private function fetchOrder(string $orderId): array
    {
        $response = $this->api()->get('/v2/checkout/orders/' . rawurlencode($orderId));
        if (!$response->isOk()) {
            throw new ApiException($response->status, $response->json(), 'PayPal order lookup failed');
        }

        return $response->json();
    }

    private function api(): HttpClient
    {
        return new HttpClient($this->baseUrl(), [
            'Authorization' => 'Bearer ' . $this->token(),
        ], 25, 2, 'paypal');
    }

    private function token(): string
    {
        $client = new HttpClient($this->baseUrl(), [
            'Authorization' => 'Basic ' . base64_encode((string) $this->config['client_id'] . ':' . (string) $this->config['client_secret']),
        ], 20, 2, 'paypal-auth');
        $response = $client->postForm('/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        $json = $response->json();
        if (!$response->isOk() || empty($json['access_token'])) {
            throw new ApiException($response->status, $json, 'PayPal authentication failed');
        }

        return (string) $json['access_token'];
    }

    private function baseUrl(): string
    {
        return ($this->config['mode'] ?? 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public static function amount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function cents(string $value): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($value), $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '0', 2, '0');

        return (int) $matches[1] * 100 + (int) substr($fraction, 0, 2);
    }
}
