<?php

declare(strict_types=1);

/**
 * Lists issued licences and their activations.
 *
 *   php bin/list.php            all licences
 *   php bin/list.php MCH-XXXX…  one licence with its activations
 */

require dirname(__DIR__) . '/src/Store.php';

$config = require dirname(__DIR__) . '/config.php';
new LicenseServer\Store((string) $config['db_path']); // creates the schema if missing

$pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$key = $argv[1] ?? null;
$sql = 'SELECT l.*, (SELECT COUNT(*) FROM activations a WHERE a.license_id = l.id) AS used FROM licenses l';
$params = [];
if ($key !== null) {
    $sql .= ' WHERE l.license_key = :key';
    $params['key'] = $key;
}
$stmt = $pdo->prepare($sql . ' ORDER BY l.id ASC');
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($rows === []) {
    fwrite(STDOUT, $key === null ? "No licences issued yet. Use: php bin/issue.php standard 365 1\n" : "Licence not found.\n");
    exit($key === null ? 0 : 1);
}

printf("%-4s %-24s %-10s %-9s %-7s %-20s %s\n", 'ID', 'KEY', 'PLAN', 'STATUS', 'USED', 'EXPIRES', 'DOMAIN / NOTES');
foreach ($rows as $row) {
    printf(
        "%-4d %-24s %-10s %-9s %-7s %-20s %s\n",
        $row['id'],
        $row['license_key'],
        $row['plan'],
        $row['status'],
        $row['used'] . '/' . $row['max_activations'],
        $row['expires_at'] ?? 'never',
        trim(($row['domain'] ?? '') . ' ' . ($row['notes'] ?? ''))
    );
    if ($key !== null) {
        $acts = $pdo->prepare('SELECT * FROM activations WHERE license_id = :id ORDER BY id');
        $acts->execute(['id' => $row['id']]);
        foreach ($acts->fetchAll() as $act) {
            printf("     activation #%d  %s  %s  v%s  last seen %s\n", $act['id'], substr((string) $act['fingerprint'], 0, 16) . '…', $act['domain'] ?? '-', $act['version'] ?? '?', $act['last_seen_at']);
        }
    }
}
