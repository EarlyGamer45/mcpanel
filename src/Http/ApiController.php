<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Queue;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Catalog;
use App\Domain\LicenseClient;
use App\Domain\ProvisioningService;

final class ApiController
{
    public function health(Request $request, array $params): Response
    {
        $checks = [];
        try {
            App::db()->value('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
        }
        $checks['queue_pending'] = Queue::pendingCount();
        $checks['queue_failed'] = Queue::failedCount();
        $checks['license'] = (string) (LicenseClient::state()['status'] ?? 'unlicensed');
        $checks['pterodactyl_configured'] = ProvisioningService::configured();
        $checks['version'] = App::version();
        $healthy = $checks['database'] === true;

        return Response::json([
            'status' => $healthy ? 'ok' : 'degraded',
            'time' => gmdate('c'),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    public function catalog(Request $request, array $params): Response
    {
        $products = [];
        foreach (Catalog::products() as $product) {
            $prices = [];
            foreach ($product['prices'] as $cycle => $row) {
                $prices[$cycle] = (int) $row['price_cents'];
            }
            $products[] = [
                'id' => (int) $product['id'],
                'name' => $product['name'],
                'slug' => $product['slug'],
                'category' => $product['category_name'],
                'tier' => $product['tier'],
                'description' => $product['description'],
                'prices_cents' => $prices,
                'min_ram_mb' => (int) $product['min_ram_mb'],
                'max_ram_mb' => (int) $product['max_ram_mb'],
            ];
        }

        return Response::json([
            'currency' => (string) config('app.currency', 'EUR'),
            'products' => $products,
        ])->withHeader('Cache-Control', 'public, max-age=60');
    }
}
