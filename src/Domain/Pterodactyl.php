<?php

declare(strict_types=1);

namespace App\Domain\Pterodactyl;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\HttpClient;
use App\Core\Logger;
use App\Core\Str;

final class PterodactylException extends \RuntimeException
{
    /** @param array<mixed> $errors */
    public function __construct(public readonly int $status, public readonly array $errors = [], string $message = '')
    {
        parent::__construct($message === '' ? 'Pterodactyl API error' : $message);
    }
}

/**
 * Base class for the official APIs. Every call goes through here: timeouts,
 * 429 retries, JSON error mapping and logging live in one place and no other
 * part of the platform talks to the Panel.
 */
abstract class ApiClient
{
    protected HttpClient $http;

    /** @param array{timeout?:int,retries?:int,http?:HttpClient} $options */
    public function __construct(protected readonly string $baseUrl, protected readonly string $apiKey, array $options = [])
    {
        $this->http = $options['http'] ?? new HttpClient(
            rtrim($baseUrl, '/'),
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
            (int) ($options['timeout'] ?? Config::get('pterodactyl.timeout', 20)),
            (int) ($options['retries'] ?? Config::get('pterodactyl.retries', 3)),
            'pterodactyl'
        );
    }

    /** @return array<string,mixed> */
    protected function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        try {
            $response = $this->http->request($method, $path, $body, $query);
        } catch (ApiException $e) {
            throw new PterodactylException($e->status, $e->errors, 'Panel unreachable: ' . $e->getMessage());
        }
        $payload = $response->json();
        if (!$response->isOk()) {
            $errors = (array) ($payload['errors'] ?? []);
            Logger::warning('pterodactyl.api_error', [
                'endpoint' => $method . ' ' . $path,
                'status' => $response->status,
                'errors' => $errors,
            ]);
            throw new PterodactylException($response->status, $errors, 'Pterodactyl API ' . $response->status . ' for ' . $method . ' ' . $path);
        }

        return $payload;
    }
}

final class ApplicationClient extends ApiClient
{
    public function __construct(string $panelUrl, string $apiKey, array $options = [])
    {
        parent::__construct(rtrim($panelUrl, '/') . '/api/application', $apiKey, $options);
    }

    /** @return array<string,mixed> */
    public function locations(): array
    {
        return $this->listAll('/locations');
    }

    /** @return array<string,mixed> */
    public function nodes(): array
    {
        return $this->listAll('/nodes');
    }

    /** @return array<string,mixed> */
    public function nests(): array
    {
        return $this->listAll('/nests');
    }

    /** @return array<string,mixed> */
    public function servers(int $perPage = 100): array
    {
        return $this->request('GET', '/servers', null, ['per_page' => $perPage]);
    }

    /** @return array<string,mixed>|null */
    public function server(int $id): ?array
    {
        try {
            return (array) ($this->request('GET', '/servers/' . $id)['attributes'] ?? []);
        } catch (PterodactylException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        $payload = $this->request('GET', '/users', null, ['filter[email]' => $email, 'per_page' => 1]);
        $entry = $payload['data'][0]['attributes'] ?? null;

        return $entry === null ? null : (array) $entry;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> user attributes
     */
    public function createUser(string $email, string $username, string $password, ?string $firstName = null, ?string $lastName = null): array
    {
        $payload = $this->request('POST', '/users', [
            'email' => $email,
            'username' => $username,
            'first_name' => $firstName ?? $username,
            'last_name' => $lastName ?? '',
            'password' => $password,
            'root_admin' => false,
            'language' => 'en',
        ]);

        return (array) ($payload['attributes'] ?? []);
    }

    /** @return list<array<string,mixed>> */
    public function allocations(int $nodeId): array
    {
        $payload = $this->request('GET', '/nodes/' . $nodeId . '/allocations', null, ['per_page' => 500]);
        $result = [];
        foreach ((array) ($payload['data'] ?? []) as $row) {
            $result[] = (array) ($row['attributes'] ?? []);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function createAllocation(int $nodeId, string $ip, int $port): array
    {
        return (array) ($this->request('POST', '/nodes/' . $nodeId . '/allocations', [
            'ip' => $ip,
            'port' => $port,
        ])['attributes'] ?? []);
    }

    /** @return array<string,mixed> */
    public function createServer(array $payload): array
    {
        return (array) ($this->request('POST', '/servers', $payload)['attributes'] ?? []);
    }

    /** @return array<string,mixed>|null */
    public function egg(int $nestId, int $eggId): ?array
    {
        try {
            $payload = $this->request('GET', '/nests/' . $nestId . '/eggs/' . $eggId, null, ['include' => 'variables']);

            return (array) ($payload['attributes'] ?? []);
        } catch (PterodactylException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function suspend(int $serverId): void
    {
        $this->request('POST', '/servers/' . $serverId . '/suspend');
    }

    public function unsuspend(int $serverId): void
    {
        $this->request('POST', '/servers/' . $serverId . '/unsuspend');
    }

    public function delete(int $serverId, bool $force = false): void
    {
        $this->request('DELETE', '/servers/' . $serverId . ($force ? '/force' : ''));
    }

    /** @param array<string,mixed> $limits @param array<string,mixed> $featureLimits */
    public function updateBuild(int $serverId, array $limits, array $featureLimits = []): void
    {
        $body = ['limits' => $limits];
        if ($featureLimits !== []) {
            $body['feature_limits'] = $featureLimits;
        }
        $this->request('PATCH', '/servers/' . $serverId . '/build', $body);
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        $checks = [];
        foreach ([
            'users' => ['GET', '/users', ['per_page' => 1]],
            'nodes' => ['GET', '/nodes', ['per_page' => 1]],
            'locations' => ['GET', '/locations', ['per_page' => 1]],
            'nests' => ['GET', '/nests', ['per_page' => 1]],
            'servers' => ['GET', '/servers', ['per_page' => 1]],
        ] as $label => [$method, $path, $query]) {
            $started = microtime(true);
            try {
                $response = $this->http->request($method, $path, null, $query);
                $checks[$label] = [
                    'ok' => $response->isOk(),
                    'status' => $response->status,
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ];
            } catch (\Throwable $e) {
                $checks[$label] = ['ok' => false, 'status' => 0, 'ms' => 0, 'error' => $e->getMessage()];
            }
        }

        return $checks;
    }

    /** @return array<string,mixed> */
    private function listAll(string $path): array
    {
        $payload = $this->request('GET', $path, null, ['per_page' => 100]);
        $result = [];
        foreach ((array) ($payload['data'] ?? []) as $row) {
            $attributes = (array) ($row['attributes'] ?? []);
            if (isset($attributes['id'])) {
                $result[(int) $attributes['id']] = $attributes;
            } else {
                $result[] = $attributes;
            }
        }

        return $result;
    }
}

/** Client API: used for live resources, power actions and the web console. */
final class ClientClient extends ApiClient
{
    public function __construct(string $panelUrl, string $apiKey, array $options = [])
    {
        parent::__construct(rtrim($panelUrl, '/') . '/api/client', $apiKey, $options);
    }

    /** @return array<string,mixed> */
    public function server(string $identifier): array
    {
        return (array) ($this->request('GET', '/servers/' . rawurlencode($identifier))['attributes'] ?? []);
    }

    /** @return array<string,mixed> */
    public function resources(string $identifier): array
    {
        return (array) ($this->request('GET', '/servers/' . rawurlencode($identifier) . '/resources')['attributes'] ?? []);
    }

    public function power(string $identifier, string $signal): void
    {
        if (!in_array($signal, ['start', 'stop', 'restart', 'kill'], true)) {
            throw new PterodactylException(422, [], 'Invalid power signal');
        }
        $this->request('POST', '/servers/' . rawurlencode($identifier) . '/power', ['signal' => $signal]);
    }

    public function command(string $identifier, string $command): void
    {
        $this->request('POST', '/servers/' . rawurlencode($identifier) . '/command', ['command' => $command]);
    }

    /** @return list<array<string,mixed>> */
    public function files(string $identifier, string $directory = '/'): array
    {
        $payload = $this->request('GET', '/servers/' . rawurlencode($identifier) . '/files/list', null, ['directory' => $directory]);
        $result = [];
        foreach ((array) ($payload['data'] ?? []) as $row) {
            $result[] = (array) ($row['attributes'] ?? []);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function websocket(string $identifier): array
    {
        return (array) ($this->request('GET', '/servers/' . rawurlencode($identifier) . '/websocket')['data'] ?? []);
    }

    public function account(): array
    {
        return (array) ($this->request('GET', '/')['attributes'] ?? []);
    }
}

/** Maps a service configuration to a Pterodactyl server creation payload. */
final class ServerSpec
{
    /**
     * @param array<string,mixed> $service
     * @param array<string,mixed> $product
     * @param array<string,mixed> $config
     * @param array<string,mixed> $egg
     * @return array<string,mixed>
     */
    public static function build(array $service, array $product, array $config, array $egg, int $panelUserId, int $allocationId): array
    {
        $environment = [];
        foreach ((array) ($egg['relationships']['variables']['data'] ?? []) as $variable) {
            $attributes = (array) ($variable['attributes'] ?? []);
            if (!empty($attributes['env_variable'])) {
                $environment[(string) $attributes['env_variable']] = (string) ($attributes['default_value'] ?? '');
            }
        }
        $version = (string) ($config['version'] ?? '');
        $software = (string) ($config['software'] ?? 'paper');
        foreach (['MINECRAFT_VERSION', 'MC_VERSION', 'VERSION'] as $key) {
            if (array_key_exists($key, $environment)) {
                $environment[$key] = $version;
            }
        }
        if (isset($environment['SERVER_JARFILE'])) {
            $environment['SERVER_JARFILE'] = $software === 'vanilla' ? 'server.jar' : strtolower($software) . '.jar';
        }
        if (isset($environment['BUILD_NUMBER'])) {
            $environment['BUILD_NUMBER'] = 'latest';
        }
        if (isset($environment['SERVER_TYPE'])) {
            $environment['SERVER_TYPE'] = $software;
        }

        return [
            'name' => Str::limit((string) $service['name'], 180),
            'user' => $panelUserId,
            'egg' => (int) ($product['egg_id'] ?? 0),
            'docker_image' => (string) ($egg['docker_image'] ?? $product['docker_image'] ?? 'ghcr.io/pterodactyl/yolks:java_21'),
            'startup' => (string) ($egg['startup'] ?? $product['startup'] ?? 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}'),
            'environment' => $environment,
            'limits' => [
                'memory' => (int) $service['ram_mb'],
                'swap' => 0,
                'disk' => (int) $service['disk_mb'],
                'io' => 500,
                'cpu' => (int) $service['cpu_percent'],
                'threads' => null,
                'oom_disabled' => true,
            ],
            'feature_limits' => [
                'databases' => 2,
                'backups' => 3,
                'allocations' => 2,
            ],
            'allocation' => ['default' => $allocationId],
            'start_on_completion' => true,
        ];
    }
}
