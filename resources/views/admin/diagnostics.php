<?php $pageTitle = __('diagnostics'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<section>
    <h1><?= e(__('diagnostics')) ?></h1>

    <div class="grid grid-3">
        <div class="stat"><span><?= e(__('queue_pending')) ?></span><strong><?= (int) $queuePending ?></strong></div>
        <div class="stat"><span><?= e(__('queue_failed')) ?></span><strong><?= (int) $queueFailed ?></strong></div>
        <div class="stat"><span><?= e(__('license')) ?></span><strong style="font-size:1.1rem"><?= e(__('license_status_' . \App\Domain\LicenseClient::status())) ?></strong></div>
    </div>

    <h2 style="margin-top:32px"><?= e(__('address_https')) ?></h2>
    <?php
        $requestHost = preg_replace('/:\d+$/', '', (string) ($address['request_host'] ?? '')) ?? '';
        $mismatch = (bool) $address['https'] !== (bool) $address['request_https']
            || ($requestHost !== '' && strcasecmp($requestHost, (string) $address['host']) !== 0);
    ?>
    <div class="table-wrap">
        <table>
            <tbody>
                <tr><td>APP_URL</td><td class="mono"><?= e((string) $address['url']) ?></td></tr>
                <tr><td><?= e(__('address_mode')) ?></td><td><?= e(__($address['is_ip'] ? 'address_mode_ip' : 'address_mode_domain')) ?></td></tr>
                <tr><td><?= e(__('address_https_active')) ?></td><td><?= $address['request_https'] ? '<span class="badge badge-ok">' . e(__('yes')) . '</span>' : '<span class="badge badge-warn">' . e(__('no')) . '</span>' ?></td></tr>
                <tr><td><?= e(__('address_cookie_secure')) ?></td><td><?= e(__($address['cookie_secure'] ? 'yes' : 'no')) ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php if (!$address['https']): ?>
        <div class="alert"><?= e(__($address['is_ip'] ? 'address_ip_warning' : 'address_http_warning')) ?></div>
        <div class="alert"><?= e(__('address_webhook_warning')) ?></div>
    <?php endif; ?>
    <?php if ($mismatch): ?>
        <div class="alert"><?= e(__('address_mismatch')) ?></div>
    <?php endif; ?>

    <h2 style="margin-top:32px">Pterodactyl</h2>
    <?php if (empty($pterodactyl['configured'])): ?>
        <div class="alert"><?= e(__('pterodactyl_not_configured')) ?></div>
    <?php else: ?>
        <p class="muted"><?= e((string) $pterodactyl['url']) ?> · <?= e((string) $pterodactyl['version']) ?></p>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= e(__('endpoint')) ?></th><th><?= e(__('status')) ?></th><th class="num">ms</th></tr></thead>
                <tbody>
                <?php foreach ($pterodactyl['checks'] as $name => $check): ?>
                    <tr>
                        <td class="mono"><?= e((string) $name) ?></td>
                        <td><?= !empty($check['ok']) ? '<span class="badge badge-ok">' . (int) $check['status'] . '</span>' : '<span class="badge badge-bad">' . e((string) ($check['status'] ?? 0)) . '</span>' ?></td>
                        <td class="num"><?= (int) ($check['ms'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted" style="font-size:.85rem"><?= e(__('pterodactyl_permissions_help')) ?></p>
    <?php endif; ?>

    <h2 style="margin-top:32px"><?= e(__('nodes')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('node')) ?></th><th><?= e(__('location')) ?></th><th class="num"><?= e(__('panel_node_id')) ?></th><th class="num">RAM</th><th><?= e(__('maintenance')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($nodes as $node): ?>
                <tr>
                    <td class="mono"><?= e((string) $node['name']) ?></td>
                    <td><?= e((string) $node['location_name']) ?></td>
                    <td class="num"><?= (int) $node['pterodactyl_node_id'] ?></td>
                    <td class="num"><?= (int) round((int) $node['memory_mb'] / 1024) ?> GB</td>
                    <td><?= (int) $node['maintenance'] === 1 ? '<span class="badge badge-warn">on</span>' : '<span class="badge badge-ok">off</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:32px"><?= e(__('healthcheck')) ?></h2>
    <p class="muted"><?= e(__('healthcheck_help')) ?></p>
    <a class="btn btn-ghost" href="<?= e(url('/api/v1/health')) ?>" target="_blank" rel="noopener"><?= e(__('open_healthcheck')) ?></a>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
