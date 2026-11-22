<?php $pageTitle = __('payment'); include __DIR__ . '/partials/head.php'; ?>
<section>
    <?php $paid = in_array((string) $order['status'], ['paid', 'provisioning', 'active'], true); ?>
    <div class="card">
        <?php if ($paid): ?>
            <span class="pill"><?= e(__('payment_confirmed')) ?></span>
            <h1 style="margin-top:14px"><?= e(__('thank_you')) ?></h1>
            <p class="muted"><?= e(__('payment_success_body')) ?></p>
        <?php elseif ($status === 'cancelled'): ?>
            <span class="pill pill-muted"><?= e(__('payment_cancelled')) ?></span>
            <h1 style="margin-top:14px"><?= e(__('payment_cancelled_title')) ?></h1>
            <p class="muted"><?= e(__('payment_cancelled_body')) ?></p>
        <?php else: ?>
            <span class="pill pill-muted"><?= e(__('pending')) ?></span>
            <h1 style="margin-top:14px"><?= e(__('payment_pending_title')) ?></h1>
            <p class="muted"><?= e(__('payment_pending_body')) ?></p>
        <?php endif; ?>

        <div class="table-wrap" style="margin:20px 0">
            <table>
                <tbody>
                <tr><th><?= e(__('order')) ?></th><td><?= e((string) $order['number']) ?></td></tr>
                <tr><th><?= e(__('invoice')) ?></th><td><?= e((string) ($invoice['number'] ?? '—')) ?></td></tr>
                <tr><th><?= e(__('total')) ?></th><td><?= e(money((int) $order['total_cents'], (string) $order['currency'])) ?></td></tr>
                <tr><th><?= e(__('status')) ?></th><td><?= status_badge((string) $order['status']) ?></td></tr>
                </tbody>
            </table>
        </div>
        <div class="hero-actions">
            <a class="btn" href="<?= e(url('/client')) ?>"><?= e(__('open_dashboard')) ?></a>
            <?php if (empty($paid)): ?>
                <a class="btn btn-ghost" href="<?= e(url('/checkout/pay/' . (int) $order['id'])) ?>"><?= e(__('retry_payment')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
