<?php
// Shown on pages that need a valid licence (appearance, products, add-ons).
$licenseLocked = !\App\Domain\LicenseClient::allowsCustomization();
if ($licenseLocked): ?>
    <div class="alert alert-danger lock-notice" role="alert">
        <strong><?= e(__('license_required_title')) ?></strong>
        <?= e(__('license_customization_blocked')) ?>
        <a href="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/settings#license')) ?>"><?= e(__('manage_license')) ?></a>
    </div>
<?php endif; ?>
