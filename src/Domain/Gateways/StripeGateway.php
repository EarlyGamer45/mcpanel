<?php

declare(strict_types=1);

namespace App\Domain\Gateways;

use App\Core\ApiException;
use App\Core\HttpClient;
use App\Core\Json;
use App\Core\Request;
use App\Domain\PaymentGateway;

/**
 * Stripe Checkout: the card data never touches this server, only the tokenised
 * session. The webhook signature is verified with the endpoint secret before any
 * order is marked as paid.
 */
final class StripeGateway implements PaymentGateway
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function code(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Carta, Apple Pay, Google Pay, SEPA';
    }

    public function description(): string
    {
        return __('gateway_stripe_desc');
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['secret'] ?? '')) !== ''
            && trim((string) ($this->config['webhook_secret'] ?? '')) !== '';
    }

    public function supportsRecurring(): bool
    {
        return true;
    }

    public function start(array $order, array $invoice, string $returnUrl): array
    {
        $form = [
            'mode' => 'payment',
            'success_url' => $returnUrl . '?status=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl . '?status=cancelled',
            'client_reference_id' => (string) $order['id'],
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower((string) $order['currency']),
            'line_items[0][price_data][unit_amount]' => (string) (int) $order['total_cents'],
            'line_items[0][price_data][product_data][name]' => 'Ordine ' . $order['number'],
            'metadata[order_id]' => (string) $order['id'],
            'metadata[invoice_id]' => (string) ($invoice['id'] ?? ''),
        ];
        if (!empty($order['customer_email'])) {
            $form['customer_email'] = (string) $order['customer_email'];
        }
        $response = $this->client()->postForm('/v1/checkout/sessions', $form);
        $json = $response->json();
        if (!$response->isOk() || empty($json['url'])) {
            throw new ApiException($response->status, $json, 'Stripe checkout session creation failed');
        }

        return ['type' => 'redirect', 'url' => (string) $json['url']];
    }

    public function handleWebhook(Request $request): ?array
    {
        $payload = $request->rawBody();
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $header = (string) ($request->header('Stripe-Signature') ?? '');
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $parts[$key][] = $value;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        if ($timestamp <= 0 || abs(time() - $timestamp) > 300) {
            throw new ApiException(400, [], 'Stripe signature timestamp outside tolerance');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw new ApiException(400, [], 'Invalid Stripe webhook signature');
        }

        $event = Json::decode($payload);
        $type = (string) ($event['type'] ?? '');
        if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return null;
        }
        $session = (array) ($event['data']['object'] ?? []);
        if (($session['payment_status'] ?? '') !== 'paid') {
            return null;
        }
        $orderId = (int) ($session['metadata']['order_id'] ?? $session['client_reference_id'] ?? 0);

        return [
            'event_id' => (string) ($event['id'] ?? ($session['id'] ?? '')),
            'type' => $type,
            'status' => 'succeeded',
            'order_id' => $orderId,
            'amount_cents' => (int) ($session['amount_total'] ?? $session['amount_subtotal'] ?? 0),
            'payment_id' => (string) ($session['payment_intent'] ?? $session['id'] ?? ''),
        ];
    }

    public function refund(array $payment, int $amountCents): void
    {
        $intent = (string) ($payment['external_id'] ?? '');
        if ($intent === '') {
            throw new ApiException(422, [], 'No Stripe payment intent on this payment');
        }
        $response = $this->client()->postForm('/v1/refunds', [
            'payment_intent' => $intent,
            'amount' => (string) $amountCents,
        ]);
        if (!$response->isOk()) {
            throw new ApiException($response->status, $response->json(), 'Stripe refund failed');
        }
    }

    private function client(): HttpClient
    {
        return new HttpClient('https://api.stripe.com', [
            'Authorization' => 'Bearer ' . (string) ($this->config['secret'] ?? ''),
        ], 25, 3, 'stripe');
    }
}
