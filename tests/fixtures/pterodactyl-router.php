<?php

declare(strict_types=1);

/**
 * Fake Pterodactyl Application API used by tests/run.php.
 *
 *   php -S 127.0.0.1:8093 tests/fixtures/pterodactyl-router.php
 *
 * Implements only the endpoints the platform calls, with the same JSON shapes
 * as Panel 1.11/1.12. State (created servers, request counters, last payload)
 * is persisted to MOCK_PTERODACTYL_STATE so the test process can assert on it.
 */

$stateFile = getenv('MOCK_PTERODACTYL_STATE') ?: sys_get_temp_dir() . '/mock-pterodactyl.json';

function state(string $file): array
{
    if (!is_file($file)) {
        return ['created' => 0, 'updated' => 0, 'suspended' => 0, 'deleted' => 0, 'users' => 0, 'last_server' => null, 'servers' => []];
    }
    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : [];
}

function saveState(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function reply(int $status, array $body = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = '/' . trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');

if ($path === '/__health') {
    reply(200, ['ok' => true]);
}
if ($path === '/__state') {
    reply(200, state($stateFile));
}
if ($path === '/__reset') {
    saveState($stateFile, ['created' => 0, 'updated' => 0, 'suspended' => 0, 'deleted' => 0, 'users' => 0, 'last_server' => null, 'servers' => []]);
    reply(200, ['ok' => true]);
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($auth !== '' && $auth !== 'Bearer test-key') {
    reply(401, ['errors' => [['code' => 'Unauthorized', 'detail' => 'bad token']]]);
}

if (preg_match('#^/api/client/servers/[^/]+/websocket$#', $path)) {
    reply(200, ['data' => ['socket' => 'wss://panel.example.test/api/client/servers/test/websocket', 'token' => 'test-websocket-token']]);
}
if (!str_starts_with($path, '/api/application')) {
    reply(404, ['errors' => [['code' => 'NotFoundHttpException']]]);
}
$endpoint = substr($path, strlen('/api/application'));
$state = state($stateFile);

// GET /users?filter[email]=...
if ($method === 'GET' && $endpoint === '/users') {
    reply(200, ['object' => 'list', 'data' => []]);
}
if ($method === 'POST' && $endpoint === '/users') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $state['users']++;
    saveState($stateFile, $state);
    reply(201, ['object' => 'user', 'attributes' => [
        'id' => 7,
        'email' => $body['email'] ?? '',
        'username' => $body['username'] ?? 'paneluser',
    ]]);
}

// GET /nodes/1/allocations
if ($method === 'GET' && preg_match('#^/nodes/(\d+)/allocations$#', $endpoint, $m)) {
    reply(200, ['object' => 'list', 'data' => [
        ['attributes' => ['id' => 11, 'ip' => '192.0.2.10', 'port' => 25565, 'assigned' => false, 'node_id' => (int) $m[1]]],
    ]]);
}

// GET /nests/1/eggs/1?include=variables
if ($method === 'GET' && preg_match('#^/nests/(\d+)/eggs/(\d+)$#', $endpoint, $m)) {
    reply(200, ['object' => 'egg', 'attributes' => [
        'id' => (int) $m[2],
        'name' => 'Paper',
        'docker_image' => 'ghcr.io/pterodactyl/yolks:java_21',
        'startup' => 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}',
        'relationships' => ['variables' => ['data' => [
            ['attributes' => ['env_variable' => 'MINECRAFT_VERSION', 'default_value' => '1.21.8']],
            ['attributes' => ['env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar']],
        ]]],
    ]]);
}

// POST /servers
if ($method === 'POST' && $endpoint === '/servers') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $id = 55;
    $state['created']++;
    $state['last_server'] = $body;
    $state['servers'][(string) $id] = ['id' => $id, 'identifier' => 'srv' . $id, 'name' => $body['name'] ?? 'server', 'status' => 'installing'];
    saveState($stateFile, $state);
    reply(200, ['object' => 'server', 'attributes' => [
        'id' => $id,
        'identifier' => 'srv' . $id,
        'name' => $body['name'] ?? 'server',
        'status' => 'installing',
    ]]);
}

// GET /servers (reconcile)
if ($method === 'GET' && $endpoint === '/servers') {
    $data = [];
    foreach ($state['servers'] as $server) {
        $data[] = ['attributes' => $server];
    }
    reply(200, ['object' => 'list', 'data' => $data]);
}

// GET /servers/55
if ($method === 'GET' && preg_match('#^/servers/(\d+)$#', $endpoint, $m)) {
    $id = (int) $m[1];
    if (!isset($state['servers'][(string) $id])) {
        reply(404, ['errors' => [['code' => 'NotFoundHttpException']]]);
    }
    $server = $state['servers'][(string) $id];
    $server['status'] = 'running';
    reply(200, ['object' => 'server', 'attributes' => $server]);
}

// PATCH /servers/55/build (the official Application API uses PATCH)
if (in_array($method, ['PATCH', 'PUT'], true) && preg_match('#^/servers/(\d+)/build$#', $endpoint)) {
    $state['updated']++;
    saveState($stateFile, $state);
    reply(200, ['object' => 'server']);
}

// POST /servers/55/suspend | unsuspend
if ($method === 'POST' && preg_match('#^/servers/(\d+)/(suspend|unsuspend)$#', $endpoint, $m)) {
    if ($m[2] === 'suspend') {
        $state['suspended']++;
        saveState($stateFile, $state);
    }
    reply(204);
}

// DELETE /servers/55
if ($method === 'DELETE' && preg_match('#^/servers/(\d+)$#', $endpoint)) {
    $state['deleted']++;
    saveState($stateFile, $state);
    reply(204);
}

// GET /api/application (version probe)
if ($method === 'GET' && $endpoint === '') {
    reply(200, ['version' => '1.11.11']);
}

reply(404, ['errors' => [['code' => 'NotFoundHttpException', 'detail' => $method . ' ' . $endpoint]]]);
