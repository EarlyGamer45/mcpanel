<?php $currentEmail = \App\Domain\UserRepository::present($currentUser ?? [])['email']; $pageTitle = __('security'); include __DIR__ . '/../partials/head.php'; ?>
<section>
    <h1><?= e(__('security')) ?></h1>
    <div class="two-col">
        <div class="stack">
        <form class="card" id="password" method="post" action="<?= e(url('/client/security/password')) ?>">
            <?= csrf_field() ?>
            <h3 class="card-title"><?= icon('shield') ?><?= e(__('change_password')) ?></h3>
            <div class="field">
                <label for="current_password"><?= e(__('current_password')) ?></label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="new_password"><?= e(__('new_password')) ?></label>
                    <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="10" required>
                    <span class="field-help"><?= e(__('password_rules')) ?></span>
                </div>
                <div class="field">
                    <label for="new_password_confirmation"><?= e(__('confirm_new_password')) ?></label>
                    <input id="new_password_confirmation" name="new_password_confirmation" type="password" autocomplete="new-password" minlength="10" required>
                </div>
            </div>
            <button class="btn" type="submit"><?= e(__('change_password')) ?></button>
            <p class="muted" style="font-size:.85rem;margin:10px 0 0"><?= e(__('change_password_help')) ?></p>
        </form>
        <form class="card" id="email" method="post" action="<?= e(url('/client/security/email')) ?>">
            <?= csrf_field() ?>
            <h3 class="card-title"><?= icon('user') ?><?= e(__('change_email')) ?></h3>
            <p class="card-hint"><?= e(__('current_email')) ?>: <strong><?= e($currentEmail !== '' ? $currentEmail : __('email_missing')) ?></strong></p>
            <div class="field-row">
                <div class="field"><label for="new_email"><?= e(__('new_email')) ?></label><input id="new_email" name="new_email" type="email" autocomplete="email" required></div>
                <div class="field"><label for="email_current_password"><?= e(__('current_password')) ?></label><input id="email_current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            </div>
            <button class="btn" type="submit"><?= e(__('change_email')) ?></button>
        </form>
        <div class="card">
            <h3 class="card-title"><?= icon('shield') ?><?= e(__('two_factor')) ?></h3>
            <?php if ($enabled): ?>
                <p><span class="badge badge-ok"><?= e(__('enabled')) ?></span></p>
                <p class="muted"><?= e(__('2fa_active_help')) ?></p>
                <form method="post" action="<?= e(url('/client/security/2fa/disable')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-danger btn-sm" type="submit"><?= e(__('disable_2fa')) ?></button>
                </form>
            <?php else: ?>
                <p class="muted"><?= e(__('2fa_setup_help')) ?></p>
                <div data-qr="<?= e($otpauth) ?>" aria-label="<?= e(__('2fa_qr_label')) ?>" style="max-width:220px;background:#fff;padding:10px;border-radius:10px;margin:12px 0"></div>
                <p class="muted" style="font-size:.82rem"><?= e(__('2fa_scan_help')) ?></p>
                <p class="mono" style="background:var(--surface-3);padding:12px;border-radius:6px;word-break:break-all"><?= e($secret) ?></p>
                <p class="muted" style="font-size:.82rem"><?= e(__('2fa_uri_help')) ?></p>
                <p class="mono" style="font-size:.72rem;word-break:break-all"><?= e($otpauth) ?></p>
                <form method="post" action="<?= e(url('/client/security/2fa')) ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="code"><?= e(__('auth_code')) ?></label>
                        <input id="code" name="code" inputmode="numeric" required>
                    </div>
                    <button class="btn" type="submit"><?= e(__('enable_2fa')) ?></button>
                </form>
            <?php endif; ?>

            <?php if ($recoveryCodes !== []): ?>
                <div class="alert" style="margin-top:16px">
                    <strong><?= e(__('recovery_codes')) ?></strong>
                    <p class="mono" style="margin:8px 0 0"><?= e(implode('   ', $recoveryCodes)) ?></p>
                    <small><?= e(__('recovery_codes_help')) ?></small>
                </div>
            <?php endif; ?>
        </div>
        </div>

        <aside class="card">
            <h3><?= e(__('active_sessions')) ?></h3>
            <ul class="clean" style="font-size:.85rem">
                <?php foreach ($sessions as $session): ?>
                    <li>
                        <?= e((string) ($session['ip'] ?? '—')) ?><br>
                        <small class="muted"><?= e(\App\Core\Str::limit((string) ($session['user_agent'] ?? ''), 60)) ?><br><?= e(fmt_date((string) $session['last_seen_at'])) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>
    </div>
</section>
<script src="<?= e(asset('vendor/qrcode.js')) ?>" defer></script>
<script src="<?= e(asset('qr-init.js')) ?>" defer></script>
<?php include __DIR__ . '/../partials/foot.php'; ?>
