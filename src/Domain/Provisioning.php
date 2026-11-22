<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Domain\Pterodactyl\ApplicationClient;
use App\Domain\Pterodactyl\PterodactylException;
use App\Domain\Pterodactyl\ServerSpec;

/**
 * Automatic provisioning through the official Pterodactyl Application API.
 * The whole flow is idempotent: the service row keeps the panel server id and a
 * deterministic provision_key, so a retried job resumes instead of creating a
 * second server.
 */
final class ProvisioningService
{
    /** @var (callable():ApplicationClient)|null test seam */
    public static $clientFactory = null;

    public static function configured(): bool
    {
        return trim((string) Config::get('pterodactyl.url', '')) !== ''
            && trim((string) Config::get('pterodactyl.application_key', '')) !== '';
    }

    public static function application(): ApplicationClient
    {
        if (self::$clientFactory !== null) {
            return (self::$clientFactory)();
        }

        return new ApplicationClient(
            (string) Config::get('pterodactyl.url', ''),
            (string) Config::get('pterodactyl.application_key', '')
        );
    }

    public static function provision(int $serviceId): void
    {
        $service = Services::find($serviceId);
        if ($service === null) {
            throw new \RuntimeException('Service ' . $serviceId . ' not found');
        }
        if ((string) $service['provisioning_state'] === 'ready' && (string) $service['status'] === 'active') {
            return;
        }
        if (!self::configured()) {
            Logger::warning('provisioning.not_configured', ['service' => $serviceId]);
            App::db()->update('services', ['provisioning_state' => 'awaiting_panel', 'updated_at' => Database::now()], 'id = :id', ['id' => $serviceId]);
            self::log($serviceId, 'config', false, 0, 'Pterodactyl credentials are not configured');

            return;
        }

        $db = App::db();
        $db->update('services', ['provisioning_state' => 'running', 'updated_at' => Database::now()], 'id = :id', ['id' => $serviceId]);
        try {
            $product = Catalog::productById((int) $service['product_id']);
            if ($product === null) {
                throw new \RuntimeException('Product missing for service ' . $serviceId);
            }
            $config = $service['config'] ?? [];
            $client = self::application();
            $user = UserRepository::find((int) $service['user_id']);
            $customer = UserRepository::present($user ?? []);
            $panelUser = $client->findUserByEmail((string) $customer['email']);
            if ($panelUser === null) {
                $panelUser = $client->createUser(
                    (string) $customer['email'],
                    mb_substr((string) $customer['name'], 0, 32) . '-' . $serviceId,
                    bin2hex(random_bytes(16)) . 'Aa1!'
                );
                self::log($serviceId, 'user.create', true, 201, 'Created panel user ' . ($panelUser['id'] ?? '?'));
            }

            $node = self::nodeFor($service);
            if ($node === null) {
                throw new \RuntimeException('No node with enough free memory for ' . $service['ram_mb'] . ' MB');
            }
            $allocation = self::allocationFor($client, (int) $node['pterodactyl_node_id'], (string) ($node['name'] ?? ''));
            $egg = null;
            if (!empty($product['nest_id']) && !empty($product['egg_id'])) {
                $egg = $client->egg((int) $product['nest_id'], (int) $product['egg_id']);
            }
            if ($egg === null) {
                throw new \RuntimeException('Egg mapping is missing for product ' . $product['slug']);
            }

            $payload = ServerSpec::build($service, $product, $config, $egg, (int) ($panelUser['id'] ?? 0), (int) $allocation['id']);
            if (empty($service['pterodactyl_server_id'])) {
                $server = $client->createServer($payload);
                $db->update('services', [
                    'pterodactyl_server_id' => (int) ($server['id'] ?? 0),
                    'external_identifier' => (string) ($server['identifier'] ?? ''),
                    'node_id' => (int) $node['id'],
                    'ip' => (string) ($allocation['ip'] ?? ''),
                    'port' => (int) ($allocation['port'] ?? 0),
                    'status' => 'installing',
                    'provisioning_state' => 'installing',
                    'updated_at' => Database::now(),
                ], 'id = :id', ['id' => $serviceId]);
                Services::recordEvent($serviceId, (string) $service['status'], 'installing', 'created on panel');
                self::log($serviceId, 'server.create', true, 200, 'Panel server ' . ($server['identifier'] ?? '?'));
            } else {
                $client->updateBuild((int) $service['pterodactyl_server_id'], $payload['limits'], $payload['feature_limits']);
                self::log($serviceId, 'server.build', true, 200, 'Reconciled existing panel server');
            }

            $db->update('services', ['provisioning_state' => 'installing', 'updated_at' => Database::now()], 'id = :id', ['id' => $serviceId]);
        } catch (\Throwable $e) {
            self::log($serviceId, 'provision', false, $e instanceof PterodactylException ? $e->status : 0, $e->getMessage());
            $db->update('services', ['provisioning_state' => 'failed', 'updated_at' => Database::now()], 'id = :id', ['id' => $serviceId]);
            Services::recordEvent($serviceId, (string) $service['status'], 'failed', $e->getMessage());
            throw $e;
        }
    }

    /** Called by a delayed job to detect the end of the egg installation. */
    public static function checkInstall(int $serviceId, int $attempt = 1): void
    {
        $service = Services::find($serviceId);
        if ($service === null || empty($service['external_identifier'])) {
            return;
        }
        if (!in_array((string) $service['status'], ['installing', 'pending'], true)) {
            return;
        }
        if (!self::configured()) {
            return;
        }
        $server = self::application()->server((int) $service['pterodactyl_server_id']);
        if ($server === null) {
            self::log($serviceId, 'install.check', false, 404, 'Server disappeared from the panel');
            Services::transition($serviceId, 'failed', 'server not found on panel');

            return;
        }
        $status = (string) ($server['status'] ?? '');
        if (in_array($status, ['running', 'starting', 'stopping', 'offline'], true)) {
            App::db()->update('services', ['provisioning_state' => 'ready', 'updated_at' => Database::now()], 'id = :id', ['id' => $serviceId]);
            Services::transition($serviceId, 'active', 'installation completed');
            self::log($serviceId, 'install.done', true, 200, 'State: ' . $status);

            return;
        }
        if ($attempt >= 20) {
            Services::transition($serviceId, 'active', 'installation verification timeout — check manually');
            App::db()->update('services', ['provisioning_state' => 'ready'], 'id = :id', ['id' => $serviceId]);

            return;
        }
        \App\Core\Queue::push(\App\Domain\Jobs\CheckInstallJob::class, ['service_id' => $serviceId, 'attempt' => $attempt + 1], 60, 'provisioning');
    }

    public static function suspend(array $service): void
    {
        if (self::configured() && !empty($service['pterodactyl_server_id'])) {
            self::application()->suspend((int) $service['pterodactyl_server_id']);
        }
        Services::transition((int) $service['id'], 'suspended', 'payment overdue');
    }

    public static function unsuspend(array $service): void
    {
        if (self::configured() && !empty($service['pterodactyl_server_id'])) {
            self::application()->unsuspend((int) $service['pterodactyl_server_id']);
        }
        Services::transition((int) $service['id'], 'active', 'reactivated after payment');
    }

    public static function terminate(array $service, bool $force = false): void
    {
        if (self::configured() && !empty($service['pterodactyl_server_id'])) {
            try {
                self::application()->delete((int) $service['pterodactyl_server_id'], $force);
            } catch (PterodactylException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        Services::transition((int) $service['id'], 'terminated', 'terminated');
    }

    public static function applyResources(array $service): void
    {
        if (!self::configured() || empty($service['pterodactyl_server_id'])) {
            return;
        }
        self::application()->updateBuild((int) $service['pterodactyl_server_id'], [
            'memory' => (int) $service['ram_mb'],
            'swap' => 0,
            'disk' => (int) $service['disk_mb'],
            'io' => 500,
            'cpu' => (int) $service['cpu_percent'],
            'threads' => null,
            'oom_disabled' => true,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function reconcile(): array
    {
        if (!self::configured()) {
            return ['error' => 'not_configured'];
        }
        $client = self::application();
        $panel = $client->servers(100);
        $known = [];
        foreach (App::db()->select('SELECT id, pterodactyl_server_id, name FROM services WHERE pterodactyl_server_id IS NOT NULL') as $row) {
            $known[(int) $row['pterodactyl_server_id']] = $row;
        }
        $orphans = [];
        foreach ((array) ($panel['data'] ?? []) as $entry) {
            $attributes = (array) ($entry['attributes'] ?? []);
            $id = (int) ($attributes['id'] ?? 0);
            if ($id > 0 && !isset($known[$id])) {
                $orphans[] = ['id' => $id, 'name' => $attributes['name'] ?? ('#' . $id), 'identifier' => $attributes['identifier'] ?? ''];
            }
        }
        $missing = [];
        $panelIds = [];
        foreach ((array) ($panel['data'] ?? []) as $entry) {
            $panelIds[(int) (($entry['attributes']['id'] ?? 0))] = true;
        }
        foreach ($known as $id => $row) {
            if (!isset($panelIds[$id])) {
                $missing[] = ['service_id' => (int) $row['id'], 'name' => $row['name'], 'panel_id' => $id];
            }
        }

        return ['orphans' => $orphans, 'missing' => $missing, 'checked_at' => gmdate('c')];
    }

    /** @return array<string,mixed> */
    public static function diagnostics(): array
    {
        if (!self::configured()) {
            return ['configured' => false, 'checks' => []];
        }

        return [
            'configured' => true,
            'url' => (string) Config::get('pterodactyl.url', ''),
            'version' => (string) Config::get('pterodactyl.version', '') ?: 'auto (1.11 / 1.12 compatible endpoints)',
            'checks' => self::application()->diagnostics(),
        ];
    }

    private static function nodeFor(array $service): ?array
    {
        if (!empty($service['node_id'])) {
            return App::db()->selectOne('SELECT * FROM nodes WHERE id = :id', ['id' => (int) $service['node_id']]);
        }

        return NodeSelector::pick($service['location_id'] === null ? null : (int) $service['location_id'], (int) $service['ram_mb']);
    }

    /** @return array<string,mixed> */
    private static function allocationFor(ApplicationClient $client, int $nodeId, string $nodeName): array
    {
        $allocations = $client->allocations($nodeId);
        foreach ($allocations as $allocation) {
            if (empty($allocation['assigned'])) {
                return $allocation;
            }
        }
        throw new \RuntimeException('No free allocation on node ' . $nodeName . '. Create free ports in the panel or adjust the range.');
    }

    private static function log(int $serviceId, string $step, bool $success, int $status, string $message): void
    {
        try {
            App::db()->insert('provisioning_logs', [
                'service_id' => $serviceId,
                'attempt' => 1,
                'step' => $step,
                'success' => $success ? 1 : 0,
                'status_code' => $status,
                'message' => mb_substr($message, 0, 2000),
                'created_at' => Database::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('provisioning.log_failed', ['error' => $e->getMessage()]);
        }
    }
}

final class NodeSelector
{
    /** @return array<string,mixed>|null */
    public static function pick(?int $locationId, int $ramMb): ?array
    {
        $db = App::db();
        $sql = 'SELECT n.*, l.name AS location_name FROM nodes n JOIN locations l ON l.id = n.location_id
                WHERE n.maintenance = 0 AND n.visible = 1';
        $bind = [];
        if ($locationId !== null) {
            $sql .= ' AND n.location_id = :location';
            $bind['location'] = $locationId;
        }
        $candidates = [];
        foreach ($db->select($sql, $bind) as $node) {
            $allocated = (int) $db->value(
                'SELECT COALESCE(SUM(ram_mb), 0) FROM services WHERE node_id = :node AND status IN (:active, :installing, :pending)',
                ['node' => (int) $node['id'], 'active' => 'active', 'installing' => 'installing', 'pending' => 'pending']
            );
            $capacity = intdiv((int) $node['memory_mb'] * max(100, (int) $node['overallocation_pct']), 100);
            if ($capacity - $allocated < $ramMb) {
                continue;
            }
            $node['allocated_mb'] = $allocated;
            $node['capacity_mb'] = $capacity;
            $node['fill_ratio'] = $capacity > 0 ? $allocated / $capacity : 1.0;
            $candidates[] = $node;
        }
        usort($candidates, static fn (array $a, array $b): int => $a['fill_ratio'] <=> $b['fill_ratio']);

        return $candidates[0] ?? null;
    }
}
