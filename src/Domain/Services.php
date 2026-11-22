<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Events;
use App\Core\Json;
use App\Core\Money;

final class ServiceStateMachine
{
    public const STATES = ['pending', 'installing', 'active', 'suspended', 'terminated', 'failed'];

    private const TRANSITIONS = [
        'pending' => ['installing', 'active', 'terminated', 'failed'],
        'installing' => ['active', 'failed', 'terminated', 'pending'],
        'active' => ['suspended', 'terminated', 'failed'],
        'suspended' => ['active', 'terminated'],
        'terminated' => [],
        'failed' => ['pending', 'installing', 'active', 'terminated'],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (!self::can($from, $to)) {
            throw new \RuntimeException(sprintf('Invalid service transition %s -> %s', $from, $to));
        }
    }
}

final class Services
{
    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $service = App::db()->selectOne('SELECT * FROM services WHERE id = :id', ['id' => $id]);
        if ($service !== null) {
            $service['config'] = Json::decode($service['config_json'] ?? null);
        }

        return $service;
    }

    /** @return array<string,mixed>|null */
    public static function findForUser(int $id, int $userId): ?array
    {
        $service = App::db()->selectOne('SELECT * FROM services WHERE id = :id AND user_id = :user', ['id' => $id, 'user' => $userId]);
        if ($service !== null) {
            $service['config'] = Json::decode($service['config_json'] ?? null);
        }

        return $service;
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        $rows = App::db()->select('SELECT s.*, p.name AS product_name, l.name AS location_name
            FROM services s
            LEFT JOIN products p ON p.id = s.product_id
            LEFT JOIN locations l ON l.id = s.location_id
            WHERE s.user_id = :user AND s.status <> :terminated
            ORDER BY s.id DESC', ['user' => $userId, 'terminated' => 'terminated']);
        foreach ($rows as &$row) {
            $row['config'] = Json::decode($row['config_json'] ?? null);
        }

        return $rows;
    }

    public static function label(?array $product, array $config, string $cycle): string
    {
        $parts = [(string) ($product['name'] ?? 'Server')];
        if (isset($config['ram_mb'])) {
            $parts[] = PriceCalculator::humanResource('ram', (int) $config['ram_mb']) . ' RAM';
        }
        if (!empty($config['version'])) {
            $parts[] = ucfirst((string) ($config['software'] ?? 'paper')) . ' ' . $config['version'];
        }
        $parts[] = __($cycle);

        return implode(' · ', $parts);
    }

    /**
     * Creates services for a paid order (or extends the ones already linked to
     * it). Returns the ids that still need provisioning.
     *
     * @param array<string,mixed> $order
     * @return list<int>
     */
    public static function provisionFromOrder(array $order): array
    {
        $db = App::db();
        $items = $db->select('SELECT * FROM order_items WHERE order_id = :order', ['order' => (int) $order['id']]);
        $created = [];
        foreach ($items as $item) {
            if (!empty($item['service_id'])) {
                $service = self::find((int) $item['service_id']);
                if ($service !== null) {
                    self::extend($service);
                }
                continue;
            }
            $provisionKey = hash('sha256', 'order-item:' . $item['id']);
            $exists = $db->selectOne('SELECT id FROM services WHERE provision_key = :key', ['key' => $provisionKey]);
            if ($exists !== null) {
                continue;
            }
            $config = Json::decode((string) ($item['config_json'] ?? ''));
            $product = Catalog::productById((int) $item['product_id']);
            $cycle = (string) $item['cycle'];
            $nextDue = Money::cycleEnd($cycle, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $serviceId = $db->insert('services', [
                'user_id' => (int) $order['user_id'],
                'order_id' => (int) $order['id'],
                'product_id' => (int) $item['product_id'],
                'name' => mb_substr(self::label($product, $config, $cycle), 0, 160),
                'status' => 'pending',
                'cycle' => $cycle,
                'price_cents' => (int) $item['total_cents'],
                'currency' => (string) ($order['currency'] ?? 'EUR'),
                'ram_mb' => (int) ($config['ram_mb'] ?? 1024),
                'cpu_percent' => (int) ($config['cpu_percent'] ?? 100),
                'disk_mb' => (int) ($config['disk_mb'] ?? 5120),
                'location_id' => isset($config['location_id']) ? (int) $config['location_id'] : null,
                'config_json' => Json::encode($config),
                'addons_json' => Json::encode($config['addon_ids'] ?? []),
                'provision_key' => $provisionKey,
                'provisioning_state' => 'queued',
                'next_due_at' => $nextDue->format('Y-m-d H:i:s'),
                'auto_renew' => 1,
                'created_at' => Database::now(),
            ]);
            $db->update('order_items', ['service_id' => $serviceId], 'id = :id', ['id' => (int) $item['id']]);
            self::recordEvent($serviceId, null, 'pending', 'created from order');
            $created[] = $serviceId;
            Events::dispatch('service.created', ['service_id' => $serviceId]);
        }

        return $created;
    }

    public static function extend(array $service, ?string $cycle = null): void
    {
        $cycle ??= (string) $service['cycle'];
        $base = $service['next_due_at'] !== null
            ? new \DateTimeImmutable((string) $service['next_due_at'], new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($base < $now) {
            $base = $now;
        }
        $next = Money::cycleEnd($cycle, $base);
        $data = ['next_due_at' => $next->format('Y-m-d H:i:s'), 'updated_at' => Database::now(), 'cycle' => $cycle];
        if ((string) $service['status'] === 'suspended') {
            $data['status'] = 'active';
            $data['suspended_at'] = null;
            $data['suspension_reason'] = null;
        }
        App::db()->update('services', $data, 'id = :id', ['id' => (int) $service['id']]);
    }

    public static function transition(int $serviceId, string $to, string $reason = '', ?int $actorId = null): void
    {
        $service = self::find($serviceId);
        if ($service === null) {
            throw new \RuntimeException('Service not found');
        }
        ServiceStateMachine::assert((string) $service['status'], $to);
        $data = ['status' => $to, 'updated_at' => Database::now()];
        if ($to === 'suspended') {
            $data['suspended_at'] = Database::now();
            $data['suspension_reason'] = $reason;
        }
        if ($to === 'active') {
            $data['suspended_at'] = null;
            $data['suspension_reason'] = null;
        }
        if ($to === 'terminated') {
            $data['terminated_at'] = Database::now();
        }
        App::db()->update('services', $data, 'id = :id', ['id' => $serviceId]);
        self::recordEvent($serviceId, (string) $service['status'], $to, $reason, $actorId);
        Audit::log('service.transition', 'service', $serviceId, ['from' => $service['status'], 'to' => $to, 'reason' => $reason]);
        Events::dispatch('service.' . $to, ['service_id' => $serviceId, 'reason' => $reason]);
    }

    /** Applies a new resource configuration from a paid upgrade quote. */
    public static function applyQuote(int $serviceId, array $quote, ?string $cycle = null): void
    {
        $config = $quote['config'];
        $data = [
            'ram_mb' => (int) $config['ram_mb'],
            'cpu_percent' => (int) $config['cpu_percent'],
            'disk_mb' => (int) $config['disk_mb'],
            'price_cents' => (int) $quote['total_cents'],
            'config_json' => Json::encode($config),
            'updated_at' => Database::now(),
        ];
        if ($cycle !== null) {
            $data['cycle'] = $cycle;
        }
        App::db()->update('services', $data, 'id = :id', ['id' => $serviceId]);
        Audit::log('service.updated', 'service', $serviceId, ['price' => (int) $quote['total_cents']]);
    }

    public static function recordEvent(int $serviceId, ?string $from, string $to, string $reason = '', ?int $actorId = null): void
    {
        App::db()->insert('service_events', [
            'service_id' => $serviceId,
            'from_state' => $from,
            'to_state' => $to,
            'reason' => $reason,
            'actor_id' => $actorId,
            'created_at' => Database::now(),
        ]);
    }

    public static function statusLabel(string $status): string
    {
        return __($status);
    }
}

/** Prorated cost of changing resources in the middle of a billing period. */
final class Prorata
{
    /** @return array{remaining_days:int,period_days:int,credit_cents:int,charge_cents:int,due_cents:int,refund_cents:int} */
    public static function quote(array $service, array $newQuote): array
    {
        $periodDays = Money::cycleDays((string) $service['cycle']);
        $nextDue = $service['next_due_at'] !== null
            ? strtotime((string) $service['next_due_at'] . ' UTC')
            : time() + $periodDays * 86400;
        $remaining = (int) max(0, min($periodDays, (int) ceil(($nextDue - time()) / 86400)));
        $credit = Money::prorata((int) $service['price_cents'], $remaining, $periodDays);
        $charge = Money::prorata((int) $newQuote['total_cents'], $remaining, $periodDays);
        $difference = $charge - $credit;

        return [
            'remaining_days' => $remaining,
            'period_days' => $periodDays,
            'credit_cents' => $credit,
            'charge_cents' => $charge,
            'due_cents' => max(0, $difference),
            'refund_cents' => max(0, -$difference),
        ];
    }
}
