<?php $pageTitle = __('dashboard'); include __DIR__ . '/../partials/head.php'; ?>
<?php if (!$verified): ?>
    <div class="alert"><?= e(__('verify_email_banner')) ?></div>
<?php endif; ?>
<section>
    <h1><?= e(__('hello')) ?>, <?= e((string) ($currentUser['username'] ?? '')) ?></h1>
    <div class="grid grid-4" style="margin:18px 0">
        <div class="stat"><span><?= e(__('active_services')) ?></span><strong><?= (int) $stats['active_services'] ?></strong></div>
        <div class="stat"><span><?= e(__('open_tickets')) ?></span><strong><?= (int) $stats['open_tickets'] ?></strong></div>
        <div class="stat"><span><?= e(__('unpaid_invoices')) ?></span><strong><?= (int) $stats['unpaid_invoices'] ?></strong></div>
        <div class="stat"><span><?= e(__('wallet')) ?></span><strong><?= e(money((int) $stats['wallet'])) ?></strong></div>
    </div>
</section>

<section>
    <h2><?= e(__('your_services')) ?></h2>
    <?php if ($services === []): ?>
        <div class="card">
            <p class="muted"><?= e(__('no_services')) ?></p>
            <a class="btn" href="<?= e(url('/catalog')) ?>"><?= e(__('order_server')) ?></a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= e(__('service')) ?></th><th><?= e(__('resources')) ?></th><th><?= e(__('address')) ?></th><th><?= e(__('renewal')) ?></th><th><?= e(__('status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($services as $service): ?>
                    <tr>
                        <td><a href="<?= e(url('/client/service/' . (int) $service['id'])) ?>"><strong><?= e((string) $service['name']) ?></strong></a><br><small class="muted"><?= e((string) ($service['location_name'] ?? '')) ?></small></td>
                        <td class="muted" style="font-size:.85rem">
                            <?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $service['ram_mb'])) ?> RAM ·
                            <?= (int) $service['cpu_percent'] ?>% CPU ·
                            <?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $service['disk_mb'])) ?>
                        </td>
                        <td class="mono"><?= $service['ip'] ? e($service['ip'] . ':' . (int) $service['port']) : '—' ?></td>
                        <td><?= e(fmt_date((string) $service['next_due_at'], 'd/m/Y')) ?><br><small class="muted"><?= e(money((int) $service['price_cents'])) ?> / <?= e(__((string) $service['cycle'])) ?></small></td>
                        <td><?= status_badge((string) $service['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="two-col">
    <div>
        <h2><?= e(__('recent_invoices')) ?></h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= e(__('number')) ?></th><th><?= e(__('date')) ?></th><th class="num"><?= e(__('total')) ?></th><th><?= e(__('status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><a href="<?= e(url('/client/invoice/' . (int) $invoice['id'] . '/pdf')) ?>"><?= e((string) $invoice['number']) ?></a></td>
                        <td><?= e(fmt_date((string) $invoice['created_at'], 'd/m/Y')) ?></td>
                        <td class="num"><?= e(money((int) $invoice['total_cents'], (string) $invoice['currency'])) ?></td>
                        <td><?= status_badge((string) $invoice['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($invoices === []): ?><tr><td colspan="4" class="muted"><?= e(__('no_invoices')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <aside>
        <h2><?= e(__('announcements')) ?></h2>
        <?php foreach ($announcements as $announcement): ?>
            <div class="card card-flat" style="margin-bottom:12px">
                <strong><?= e((string) $announcement['title']) ?></strong>
                <p class="muted" style="font-size:.88rem;margin:6px 0 0"><?= e((string) $announcement['body']) ?></p>
            </div>
        <?php endforeach; ?>
        <a class="btn btn-ghost btn-block" href="<?= e(url('/client/tickets')) ?>"><?= e(__('open_ticket')) ?></a>
    </aside>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
