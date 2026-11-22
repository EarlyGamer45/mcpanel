<?php $pageTitle = __('payment'); include __DIR__ . '/partials/head.php'; ?>
<section>
    <h1><?= e(__('payment')) ?></h1>
    <p class="muted"><?= e(__('order_number')) ?> <strong><?= e((string) $order['number']) ?></strong> · <?= e(fmt_date((string) $order['created_at'], 'd/m/Y')) ?></p>

    <?php if ($instructions !== ''): ?>
        <div class="alert"><?= nl2br(e($instructions)) ?></div>
    <?php endif; ?>

    <div class="two-col">
        <div class="card">
            <h3><?= e(__('choose_payment_method')) ?></h3>
            <form method="post" action="<?= e(url('/checkout/pay/' . (int) $order['id'])) ?>">
                <?= csrf_field() ?>
                <?php foreach ($gateways as $gateway): ?>
                    <label class="addon" style="margin-bottom:10px">
                        <input type="radio" name="gateway" value="<?= e($gateway->code()) ?>" <?= $gateway->code() === 'stripe' ? 'checked' : '' ?> required>
                        <span>
                            <strong><?= e($gateway->label()) ?></strong><br>
                            <small class="muted"><?= e($gateway->description()) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
                <?php if ($gateways === []): ?>
                    <p class="muted"><?= e(__('no_gateway_configured')) ?></p>
                <?php else: ?>
                    <button class="btn btn-block" type="submit" style="margin-top:12px"><?= e(__('pay_now')) ?></button>
                <?php endif; ?>
            </form>
            <p class="muted" style="font-size:.8rem;margin-top:12px"><?= e(__('payment_security_note')) ?></p>
        </div>

        <aside class="card summary">
            <h3><?= e(__('summary')) ?></h3>
            <div class="row"><span><?= e(__('order')) ?></span><span><?= e((string) $order['number']) ?></span></div>
            <div class="row"><span><?= e(__('invoice')) ?></span><span><?= e((string) ($invoice['number'] ?? '—')) ?></span></div>
            <div class="row"><span><?= e(__('status')) ?></span><span><?= status_badge((string) $order['status']) ?></span></div>
            <div class="total"><span><?= e(__('total')) ?></span><span><?= e(money((int) $order['total_cents'], (string) $order['currency'])) ?></span></div>
            <small class="muted"><?= e(__('vat_included')) ?></small>
            <p class="muted" style="font-size:.85rem;margin-top:14px"><?= e(__('wallet_balance')) ?>: <strong><?= e(money((int) $wallet)) ?></strong></p>
            <a class="btn btn-ghost btn-block" href="<?= e(url('/client')) ?>"><?= e(__('back_to_dashboard')) ?></a>
        </aside>
    </div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
