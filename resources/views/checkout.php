<?php $pageTitle = __('checkout'); include __DIR__ . '/partials/head.php'; ?>
<section>
    <h1><?= e(__('checkout')) ?></h1>
    <?php if (!$licenseOk): ?>
        <div class="alert alert-danger"><?= e(__('sales_closed_public')) ?></div>
    <?php endif; ?>
    <div class="two-col">
        <form method="post" action="<?= e(url('/checkout')) ?>" class="card">
            <?= csrf_field() ?>
            <h3><?= e(__('billing_details')) ?></h3>
            <div class="field-row">
                <div class="field">
                    <label><?= e(__('email')) ?></label>
                    <input type="email" value="<?= e($profile['email']) ?>" readonly>
                </div>
                <div class="field">
                    <label><?= e(__('country')) ?></label>
                    <input type="text" value="<?= e($profile['country']) ?>" readonly>
                </div>
            </div>
            <p class="muted" style="font-size:.85rem">
                <?= e(__('billing_details_help')) ?>
                <a href="<?= e(url('/client/profile')) ?>"><?= e(__('edit_profile')) ?></a>
            </p>
            <?php if ($profile['vat'] !== ''): ?>
                <p class="muted" style="font-size:.85rem">VAT: <?= e($profile['vat']) ?> · <?= e($profile['company']) ?></p>
            <?php endif; ?>

            <div class="field">
                <label for="coupon"><?= e(__('coupon_code')) ?></label>
                <input type="text" id="coupon" name="coupon" placeholder="WELCOME10" autocomplete="off">
            </div>

            <label class="checkbox" style="margin:16px 0">
                <input type="checkbox" name="terms" value="1" required>
                <span>
                    <?= e(__('accept_terms')) ?>
                    <a href="<?= e(url('/legal/terms')) ?>" target="_blank" rel="noopener"><?= e(__('terms')) ?></a>,
                    <a href="<?= e(url('/legal/privacy')) ?>" target="_blank" rel="noopener"><?= e(__('privacy')) ?></a>
                    <?= e(__('and')) ?>
                    <a href="<?= e(url('/legal/aup')) ?>" target="_blank" rel="noopener">AUP</a>.
                    <?= e(__('eula_notice')) ?>
                </span>
            </label>

            <button class="btn btn-block" type="submit" <?= !$licenseOk ? 'disabled' : '' ?>><?= e(__('create_order_and_pay')) ?></button>
            <p class="muted" style="font-size:.8rem;margin-top:10px"><?= e(__('withdrawal_notice')) ?></p>
        </form>

        <aside class="card summary">
            <h3><?= e(__('order_summary')) ?></h3>
            <?php foreach ($items as $item): ?>
                <div class="row">
                    <span><?= e((string) $item['product_name']) ?><br><small class="muted"><?= e(__((string) $item['cycle'])) ?></small></span>
                    <span><?= e(money((int) $item['price_cents'])) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="total"><span><?= e(__('total')) ?></span><span><?= e(money((int) $total)) ?></span></div>
            <small class="muted"><?= e(__('vat_included')) ?></small>
            <hr style="border:0;border-top:1px solid var(--border);margin:16px 0">
            <h3><?= e(__('payment_methods')) ?></h3>
            <ul class="clean" style="font-size:.88rem">
                <?php foreach ($gateways as $gateway): ?>
                    <li><?= e($gateway->label()) ?></li>
                <?php endforeach; ?>
                <?php if ($gateways === []): ?>
                    <li class="muted"><?= e(__('no_gateway_configured')) ?></li>
                <?php endif; ?>
            </ul>
            <p class="muted" style="font-size:.85rem"><?= e(__('wallet_balance')) ?>: <strong><?= e(money((int) $wallet)) ?></strong></p>
        </aside>
    </div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
