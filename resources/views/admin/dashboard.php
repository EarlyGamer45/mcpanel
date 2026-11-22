<?php $pageTitle = __('admin_panel'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<section>
    <h1><?= e(__('dashboard')) ?></h1>
    <div class="grid grid-4" style="margin:18px 0">
        <div class="stat"><span><?= e(__('revenue_month')) ?></span><strong><?= e(money((int) $revenue)) ?></strong></div>
        <div class="stat"><span>MRR</span><strong><?= e(money((int) $mrr)) ?></strong></div>
        <div class="stat"><span><?= e(__('new_customers')) ?></span><strong><?= (int) $newCustomers ?></strong></div>
        <div class="stat"><span><?= e(__('active_services')) ?></span><strong><?= (int) $activeServices ?></strong></div>
        <div class="stat"><span><?= e(__('suspended_services')) ?></span><strong><?= (int) $suspendedServices ?></strong></div>
        <div class="stat"><span><?= e(__('open_tickets')) ?></span><strong><?= (int) $openTickets ?></strong></div>
        <div class="stat"><span><?= e(__('pending_orders')) ?></span><strong><?= (int) $pendingOrders ?></strong></div>
        <div class="stat"><span><?= e(__('failed_provisions')) ?></span><strong><?= (int) $failedProvisions ?></strong></div>
    </div>

    <?php if ((int) $failedProvisions > 0): ?>
        <div class="alert alert-danger">
            <?= e(__('failed_provisions_help')) ?>
            <a href="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/services')) ?>"><?= e(__('services')) ?></a>
        </div>
    <?php endif; ?>
    <?php if ((int) $pendingTransfers > 0): ?>
        <div class="alert"><?= e(__('pending_topups_help')) ?>: <?= (int) $pendingTransfers ?></div>
    <?php endif; ?>
</section>

<section class="two-col">
    <div>
        <h2><?= e(__('recent_orders')) ?></h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= e(__('number')) ?></th><th><?= e(__('client')) ?></th><th class="num"><?= e(__('total')) ?></th><th><?= e(__('status')) ?></th><th><?= e(__('date')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($recentOrders as $order): ?>
                    <tr>
                        <td class="mono"><?= e((string) $order['number']) ?></td>
                        <td><?= e((string) $order['username']) ?></td>
                        <td class="num"><?= e(money((int) $order['total_cents'], (string) $order['currency'])) ?></td>
                        <td><?= status_badge((string) $order['status']) ?></td>
                        <td><?= e(fmt_date((string) $order['created_at'], 'd/m/Y H:i')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <aside>
        <h2><?= e(__('node_capacity')) ?></h2>
        <?php foreach ($nodes as $node): ?>
            <div class="card" style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;gap:10px">
                    <strong class="mono"><?= e((string) $node['name']) ?></strong>
                    <span class="muted"><?= e((string) $node['location_name']) ?></span>
                </div>
                <p class="muted" style="font-size:.85rem;margin:6px 0">
                    <?= (int) round(((int) $node['allocated_mb']) / 1024) ?> / <?= (int) round(((int) $node['capacity_mb']) / 1024) ?> GB ·
                    <?= e(__('overallocation')) ?> <?= (int) $node['overallocation_pct'] ?>%
                </p>
                <div class="progress <?= (int) $node['fill_pct'] > 90 ? 'bad' : ((int) $node['fill_pct'] > 75 ? 'warn' : '') ?>"><span style="width:<?= (int) $node['fill_pct'] ?>%"></span></div>
            </div>
        <?php endforeach; ?>
        <div class="card">
            <h3><?= e(__('license')) ?></h3>
            <?php $licStatusNow = \App\Domain\LicenseClient::status(); ?>
            <p><span class="badge <?= \App\Domain\LicenseClient::isValid() ? ($licStatusNow === 'grace' ? 'badge-warn' : 'badge-ok') : 'badge-bad' ?>"><?= e(__('license_status_' . $licStatusNow)) ?></span></p>
            <p class="muted" style="font-size:.85rem"><?= e((string) ($license['plan'] ?? '—')) ?> · <?= e(fmt_date($license['expires_at'] ?? null, 'd/m/Y')) ?></p>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/settings#license')) ?>"><?= e(__('manage_license')) ?></a>
        </div>
    </aside>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
