<?php $pageTitle = __('settings'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<?php $adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/'); ?>
<section>
    <div class="page-head"><div><h1><?= e(__('settings')) ?></h1><p><?= e(__('settings_lead')) ?></p></div></div>
    <?php include __DIR__ . '/../partials/license_card.php'; ?>
    <form method="post" action="<?= e(url($adminBase . '/settings')) ?>" style="margin-top:18px">
        <?= csrf_field() ?>
        <div class="card">
            <div class="row-between">
                <div>
                    <h3 class="card-title" style="margin-bottom:4px"><?= icon('palette') ?><?= e(__('brand')) ?></h3>
                    <p class="muted" style="margin:0;font-size:.9rem"><?= e(__('brand_moved_to_appearance')) ?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="<?= e(url($adminBase . '/appearance')) ?>"><?= icon('palette') ?><?= e(__('appearance')) ?></a>
            </div>
            <div class="field" style="margin-top:16px"><label for="mail_footer"><?= e(__('mail_footer')) ?></label><input id="mail_footer" name="mail_footer" value="<?= e((string) ($settings['mail_footer'] ?? '')) ?>"></div>
        </div>

        <div class="card" style="margin-top:18px">
            <h3 class="card-title"><?= icon('file') ?><?= e(__('company_data')) ?></h3>
            <div class="field-row">
                <div class="field"><label for="company_name"><?= e(__('company_name')) ?></label><input id="company_name" name="company_name" value="<?= e((string) ($settings['company_name'] ?? '')) ?>"></div>
                <div class="field"><label for="company_vat"><?= e(__('vat_number')) ?></label><input id="company_vat" name="company_vat" value="<?= e((string) ($settings['company_vat'] ?? '')) ?>"></div>
            </div>
            <div class="field-row">
                <div class="field"><label for="company_address"><?= e(__('address')) ?></label><input id="company_address" name="company_address" value="<?= e((string) ($settings['company_address'] ?? '')) ?>"></div>
                <div class="field"><label for="company_city"><?= e(__('city')) ?></label><input id="company_city" name="company_city" value="<?= e((string) ($settings['company_city'] ?? '')) ?>"></div>
                <div class="field"><label for="company_country"><?= e(__('country')) ?></label><input id="company_country" name="company_country" maxlength="2" value="<?= e((string) ($settings['company_country'] ?? 'IT')) ?>"></div>
            </div>
            <div class="field-row">
                <div class="field"><label for="company_email"><?= e(__('email')) ?></label><input id="company_email" name="company_email" type="email" value="<?= e((string) ($settings['company_email'] ?? '')) ?>"></div>
                <div class="field"><label for="invoice_footer"><?= e(__('invoice_footer')) ?></label><input id="invoice_footer" name="invoice_footer" value="<?= e((string) ($settings['invoice_footer'] ?? '')) ?>"></div>
            </div>
        </div>

        <div class="card" style="margin-top:18px">
            <h3 class="card-title"><?= icon('clock') ?><?= e(__('automation')) ?></h3>
            <div class="field-row">
                <div class="field"><label for="renew_notice_days"><?= e(__('renew_notice_days')) ?></label><input id="renew_notice_days" name="renew_notice_days" type="number" value="<?= (int) ($settings['renew_notice_days'] ?? 7) ?>"></div>
                <div class="field"><label for="grace_days"><?= e(__('grace_days')) ?></label><input id="grace_days" name="grace_days" type="number" value="<?= (int) ($settings['grace_days'] ?? 3) ?>"></div>
                <div class="field"><label for="terminate_after_days"><?= e(__('terminate_after_days')) ?></label><input id="terminate_after_days" name="terminate_after_days" type="number" value="<?= (int) ($settings['terminate_after_days'] ?? 14) ?>"></div>
                <div class="field"><label for="risk_review_threshold"><?= e(__('risk_threshold')) ?></label><input id="risk_review_threshold" name="risk_review_threshold" type="number" value="<?= (int) ($settings['risk_review_threshold'] ?? 70) ?>"></div>
            </div>
            <div class="field-row">
                <div class="field"><label class="checkbox"><input type="checkbox" name="auto_charge_wallet" value="1" <?= !empty($settings['auto_charge_wallet']) ? 'checked' : '' ?>><span><?= e(__('auto_charge_wallet')) ?></span></label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="enforce_admin_2fa" value="1" <?= !empty($settings['enforce_admin_2fa']) ? 'checked' : '' ?>><span><?= e(__('enforce_admin_2fa')) ?></span></label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="maintenance_mode" value="1" <?= !empty($settings['maintenance_mode']) ? 'checked' : '' ?>><span><?= e(__('maintenance_mode')) ?></span></label></div>
            </div>
        </div>

        <div class="sticky-actions"><button class="btn btn-lg" type="submit"><?= icon('check') ?><?= e(__('save')) ?></button></div>
    </form>

    <section style="margin-top:36px">
        <h2><?= e(__('feature_flags')) ?></h2>
        <p class="muted"><?= e(__('feature_flags_help')) ?></p>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= e(__('name')) ?></th><th><?= e(__('enabled')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($featureFlags as $flag): ?>
                    <tr><td class="mono"><?= e((string) $flag['name']) ?></td><td><?= (int) $flag['enabled'] === 1 ? '<span class="badge badge-ok">on</span>' : '<span class="badge badge-muted">off</span>' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
