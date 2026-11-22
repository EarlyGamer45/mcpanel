<?php
// Licence status and activation form (Settings page).
$licAdmin = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$licStatus = \App\Domain\LicenseClient::status();
$licValid = \App\Domain\LicenseClient::isValid();
$licState = \App\Domain\LicenseClient::state();
$licBadge = match ($licStatus) {
    'active', 'lifetime' => 'badge-ok',
    'grace' => 'badge-warn',
    default => 'badge-bad',
};
?>
<div class="card" id="license">
    <h3 class="card-title"><?= icon('shield') ?><?= e(__('license')) ?></h3>
    <table class="kv">
        <tr><th><?= e(__('current_status')) ?></th><td><span class="badge <?= $licBadge ?>"><?= e(__('license_status_' . $licStatus)) ?></span></td></tr>
        <?php if (!empty($licState['plan'])): ?><tr><th><?= e(__('license_plan')) ?></th><td><?= e((string) $licState['plan']) ?></td></tr><?php endif; ?>
        <tr><th><?= e(__('license_expires')) ?></th><td><?= !empty($licState['expires_at']) ? e(fmt_date((string) $licState['expires_at'], 'd/m/Y')) : e($licStatus === 'unlicensed' ? '—' : __('license_never')) ?></td></tr>
        <tr><th><?= e(__('license_server')) ?></th><td class="mono"><?= e(\App\Domain\LicenseClient::serverUrl() !== '' ? \App\Domain\LicenseClient::serverUrl() : '—') ?></td></tr>
        <tr><th><?= e(__('license_public_key')) ?></th><td><?= \App\Domain\LicenseClient::hasPublicKey() ? '<span class="badge badge-ok">' . e(__('configured')) . '</span>' : '<span class="badge badge-bad">' . e(__('license_public_key_missing_short')) . '</span>' ?></td></tr>
        <?php if (!empty($licState['last_check_at'])): ?><tr><th><?= e(__('license_last_check')) ?></th><td><?= e(fmt_date((string) $licState['last_check_at'], 'd/m/Y H:i')) ?></td></tr><?php endif; ?>
        <tr><th><?= e(__('license_fingerprint')) ?></th><td class="mono" style="font-size:.78rem;word-break:break-all"><?= e(\App\Core\App::fingerprint()) ?></td></tr>
    </table>
    <?php if (!$licValid): ?>
        <div class="alert alert-danger" style="margin-top:14px"><?= e(__('license_locked_features')) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url($licAdmin . '/license')) ?>" style="margin-top:16px">
        <?= csrf_field() ?>
        <div class="field-row">
            <div class="field"><label for="license_key"><?= e(__('license_key')) ?></label><input id="license_key" name="license_key" autocomplete="off" placeholder="MCH-XXXX-XXXX-XXXX-XXXX" class="mono" required></div>
            <div class="field"><label for="license_server"><?= e(__('license_server')) ?></label><input id="license_server" name="license_server" placeholder="<?= e(\App\Domain\LicenseClient::serverUrl() !== '' ? \App\Domain\LicenseClient::serverUrl() : 'http://127.0.0.1:8090') ?>" class="mono"></div>
        </div>
        <?php if (!\App\Domain\LicenseClient::hasPublicKey()): ?>
            <div class="field">
                <label for="license_public_key"><?= e(__('license_public_key')) ?></label>
                <input id="license_public_key" name="license_public_key" class="mono" autocomplete="off" maxlength="60" placeholder="<?= e(__('license_public_key_placeholder')) ?>">
                <span class="field-help"><?= e(__('license_public_key_field_help')) ?></span>
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <button class="btn" type="submit"><?= e(__('license_activate')) ?></button>
            <button class="btn btn-ghost" type="submit" form="license-check-form"><?= e(__('license_check_now')) ?></button>
        </div>
    </form>
    <form id="license-check-form" method="post" action="<?= e(url($licAdmin . '/license/check')) ?>"><?= csrf_field() ?></form>
</div>
