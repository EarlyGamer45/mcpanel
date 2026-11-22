<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Domain\PaymentService;

final class WebhookController
{
    public function stripe(Request $request, array $params): Response
    {
        return $this->handle('stripe', $request);
    }

    public function paypal(Request $request, array $params): Response
    {
        return $this->handle('paypal', $request);
    }

    private function handle(string $gateway, Request $request): Response
    {
        try {
            $result = PaymentService::processWebhook($gateway, $request);

            return Response::json(['received' => true] + $result, 200);
        } catch (\App\Core\ApiException $e) {
            Logger::warning('webhook.rejected', ['gateway' => $gateway, 'status' => $e->status, 'error' => $e->getMessage()]);

            return Response::json(['received' => false, 'error' => $e->getMessage()], $e->status >= 400 ? $e->status : 400);
        } catch (\Throwable $e) {
            Logger::error('webhook.error', ['gateway' => $gateway, 'error' => $e->getMessage()]);

            // 500 asks the gateway to retry later; processing is idempotent.
            return Response::json(['received' => false], 500);
        }
    }
}
