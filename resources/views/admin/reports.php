<?php $pageTitle = __('reports'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<section>
    <h1><?= e(__('reports')) ?></h1>
    <p class="muted"><?= e(__('reports_help')) ?></p>

    <div class="grid grid-3" style="margin-top:16px">
        <div class="stat"><span><?= e(__('mrr')) ?></span><strong><?= e(money((int) $mrr)) ?></strong></div>
        <div class="stat"><span><?= e(__('arpu')) ?></span><strong><?= e(money((int) $arpu)) ?></strong><small class="muted"><?= (int) $activeCustomers ?> <?= e(__('active_customers')) ?></small></div>
        <div class="stat"><span><?= e(__('churn_30d')) ?></span><strong><?= (int) $churnPct ?>%</strong><small class="muted"><?= (int) $terminated30 ?> <?= e(__('terminated')) ?></small></div>
        <div class="stat"><span><?= e(__('conversion_30d')) ?></span><strong><?= (int) $conversionPct ?>%</strong><small class="muted"><?= (int) $paid30 ?> / <?= (int) $orders30 ?> <?= e(__('orders')) ?></small></div>
        <div class="stat"><span><?= e(__('active_services')) ?></span><strong><?= (int) $activeServices ?></strong></div>
    </div>

    <h2 style="margin-top:32px"><?= e(__('revenue_12m')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('month')) ?></th><th><?= e(__('gross_revenue')) ?></th><th class="num"><?= e(__('invoices')) ?></th><th style="width:40%"></th></tr></thead>
            <tbody>
            <?php foreach ($months as $month): ?>
                <tr>
                    <td class="mono"><?= e((string) $month['label']) ?></td>
                    <td class="num"><?= e(money((int) $month['total'])) ?></td>
                    <td class="num"><?= (int) $month['invoices'] ?></td>
                    <td><span style="display:block;height:8px;border-radius:4px;background:var(--brand);width:<?= (int) $month['width'] ?>%;min-width:2px"></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="grid grid-2" style="margin-top:32px">
        <div>
            <h2><?= e(__('new_orders_by_product')) ?></h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th><?= e(__('name')) ?></th><th class="num"><?= e(__('orders')) ?></th><th class="num"><?= e(__('revenue')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($byProduct as $row): ?>
                        <tr><td><?= e((string) $row['name']) ?></td><td class="num"><?= (int) $row['orders'] ?></td><td class="num"><?= e(money((int) $row['total'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($byProduct === []): ?><tr><td colspan="3" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div>
            <h2><?= e(__('revenue_by_gateway')) ?></h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th><?= e(__('payment_methods')) ?></th><th class="num"><?= e(__('payments')) ?></th><th class="num"><?= e(__('revenue')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($byGateway as $row): ?>
                        <tr><td class="mono"><?= e((string) $row['gateway']) ?></td><td class="num"><?= (int) $row['payments'] ?></td><td class="num"><?= e(money((int) $row['total'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($byGateway === []): ?><tr><td colspan="3" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <h2 style="margin-top:32px"><?= e(__('coupon_performance')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('coupon_code')) ?></th><th class="num"><?= e(__('uses')) ?></th><th class="num"><?= e(__('discount_given')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($coupons as $coupon): ?>
                <tr><td class="mono"><?= e((string) $coupon['code']) ?></td><td class="num"><?= (int) $coupon['uses'] ?></td><td class="num"><?= e(money((int) $coupon['discount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($coupons === []): ?><tr><td colspan="3" class="muted"><?= e(__('no_coupons')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:32px"><?= e(__('node_margin')) ?></h2>
    <p class="muted"><?= e(__('node_margin_help')) ?></p>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('node')) ?></th><th><?= e(__('location')) ?></th><th class="num"><?= e(__('revenue')) ?></th><th class="num"><?= e(__('cost')) ?></th><th class="num"><?= e(__('margin')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($nodes as $node): ?>
                <tr>
                    <td class="mono"><?= e((string) $node['name']) ?></td>
                    <td><?= e((string) $node['location']) ?></td>
                    <td class="num"><?= e(money((int) $node['revenue'])) ?></td>
                    <td class="num"><?= e(money((int) $node['cost'])) ?></td>
                    <td class="num" style="<?= (int) $node['margin'] < 0 ? 'color:#b91c1c' : '' ?>"><?= e(money((int) $node['margin'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($nodes === []): ?><tr><td colspan="5" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
