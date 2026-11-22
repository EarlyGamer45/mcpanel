<?php $pageTitle = __('installation'); include __DIR__ . '/partials/head.php'; ?>
<section style="max-width:620px;margin:24px auto">
    <span class="pill"><?= e(__('first_run')) ?></span>
    <h1><?= e(__('installation')) ?></h1>
    <p class="muted"><?= e(__('install_lead')) ?></p>

    <?php if ($keyMissing): ?>
        <div class="alert alert-danger">
            <?= e(__('install_missing_key')) ?><br>
            <code>php bin/console key:generate</code>
        </div>
    <?php endif; ?>
    <?php if ($blindIndexMissing): ?>
        <div class="alert alert-danger">
            <?= e(__('install_missing_blind_key')) ?><br>
            <code>php bin/console key:generate --blind</code>
        </div>
    <?php endif; ?>
    <?php if ($dbError !== ''): ?>
        <div class="alert alert-danger">
            <?= e(__('install_db_error')) ?> (<?= e($dbDriver) ?>)<br><code><?= e($dbError) ?></code>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/install')) ?>" class="card" style="margin-top:18px">
        <?= csrf_field() ?>
        <div class="field">
            <label for="site_name"><?= e(__('site_name')) ?></label>
            <input id="site_name" name="site_name" required value="MCPanel Hosting">
        </div>
        <div class="field">
            <label for="company_name"><?= e(__('company_name')) ?></label>
            <input id="company_name" name="company_name" required value="Your Company Ltd">
        </div>
        <div class="field-row">
            <div class="field">
                <label for="country"><?= e(__('country')) ?></label>
                <select id="country" name="country"><option value="IT">Italia</option><option value="DE">Deutschland</option><option value="FR">France</option><option value="ES">España</option></select>
            </div>
            <div class="field">
                <label for="username"><?= e(__('admin_username')) ?></label>
                <input id="username" name="username" required minlength="3" maxlength="32">
            </div>
        </div>
        <div class="field">
            <label for="email"><?= e(__('admin_email')) ?></label>
            <input id="email" name="email" type="email" required>
        </div>
        <div class="field">
            <label for="password"><?= e(__('admin_password')) ?></label>
            <input id="password" name="password" type="password" minlength="10" required autocomplete="new-password">
        </div>
        <button class="btn btn-block" type="submit" <?= ($keyMissing || $dbError !== '') ? 'disabled' : '' ?>><?= e(__('complete_installation')) ?></button>
        <p class="muted" style="font-size:.8rem;margin-top:10px"><?= e(__('install_note')) ?></p>
    </form>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
