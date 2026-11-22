<?php $pageTitle = __('create_account'); include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:520px;margin:32px auto">
    <h1><?= e(__('create_account')) ?></h1>
    <p class="muted"><?= e(__('register_lead')) ?></p>
    <form method="post" action="<?= e(url('/register')) ?>" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
        <div class="field">
            <label for="username"><?= e(__('username')) ?></label>
            <input id="username" name="username" required minlength="3" maxlength="32" value="<?= e((string) old('username', '')) ?>">
        </div>
        <div class="field">
            <label for="email"><?= e(__('email')) ?></label>
            <input id="email" name="email" type="email" required value="<?= e((string) old('email', '')) ?>">
        </div>
        <div class="field-row">
            <div class="field">
                <label for="password"><?= e(__('password')) ?></label>
                <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
            </div>
            <div class="field">
                <label for="country"><?= e(__('country')) ?></label>
                <select id="country" name="country">
                    <option value="IT">Italia</option>
                    <option value="DE">Deutschland</option>
                    <option value="FR">France</option>
                    <option value="ES">España</option>
                    <option value="GB">United Kingdom</option>
                    <option value="US">United States</option>
                </select>
            </div>
        </div>
        <label class="checkbox" style="margin-bottom:16px">
            <input type="checkbox" name="terms" value="1" required>
            <span><?= e(__('accept_terms')) ?> <a href="<?= e(url('/legal/terms')) ?>" target="_blank" rel="noopener"><?= e(__('terms')) ?></a> · <a href="<?= e(url('/legal/privacy')) ?>" target="_blank" rel="noopener"><?= e(__('privacy')) ?></a></span>
        </label>
        <button class="btn btn-block" type="submit"><?= e(__('create_account')) ?></button>
        <p style="margin:14px 0 0;font-size:.88rem"><a href="<?= e(url('/login')) ?>"><?= e(__('already_registered')) ?></a></p>
    </form>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
