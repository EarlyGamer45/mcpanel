<?php $pageTitle = __('cart'); include __DIR__ . '/partials/head.php'; ?>
<section>
    <h1><?= e(__('cart')) ?></h1>
    <?php if ($items === []): ?>
        <div class="card">
            <p class="muted"><?= e(__('cart_empty')) ?></p>
            <a class="btn" href="<?= e(url('/catalog')) ?>"><?= e(__('browse_plans')) ?></a>
        </div>
    <?php else: ?>
        <div class="two-col">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th><?= e(__('service')) ?></th>
                        <th><?= e(__('configuration')) ?></th>
                        <th><?= e(__('cycle')) ?></th>
                        <th class="num"><?= e(__('price')) ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php $config = $item['config']; ?>
                        <tr>
                            <td><strong><?= e((string) $item['product_name']) ?></strong></td>
                            <td class="muted" style="font-size:.85rem">
                                <?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) ($config['ram_mb'] ?? 0))) ?> RAM ·
                                <?= (int) ($config['cpu_percent'] ?? 0) ?>% CPU ·
                                <?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) ($config['disk_mb'] ?? 0))) ?> ·
                                <?= e(ucfirst((string) ($config['software'] ?? 'paper'))) ?> <?= e((string) ($config['version'] ?? '')) ?>
                                <?php if (!empty($item['location_name'])): ?> · <?= e((string) $item['location_name']) ?><?php endif; ?>
                            </td>
                            <td><?= e(__((string) $item['cycle'])) ?></td>
                            <td class="num"><?= e(money((int) $item['price_cents'])) ?></td>
                            <td>
                                <form method="post" action="<?= e(url('/cart/remove')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('remove')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <aside class="card summary">
                <h3><?= e(__('summary')) ?></h3>
                <div class="row"><span><?= e(__('services')) ?></span><span><?= count($items) ?></span></div>
                <div class="total"><span><?= e(__('total')) ?></span><span><?= e(money((int) $total)) ?></span></div>
                <small class="muted"><?= e(__('vat_included')) ?></small>
                <?php if (\App\Domain\LicenseClient::allowsSales()): ?>
                    <a class="btn btn-block" style="margin-top:16px" href="<?= e(url('/checkout')) ?>"><?= e(__('proceed_checkout')) ?></a>
                <?php else: ?>
                    <div class="alert alert-danger" style="margin:16px 0 0"><?= e(__('sales_closed_public')) ?></div>
                <?php endif; ?>
                <a class="btn btn-ghost btn-block" style="margin-top:8px" href="<?= e(url('/catalog')) ?>"><?= e(__('continue_shopping')) ?></a>
            </aside>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
