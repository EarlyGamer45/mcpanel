<?php $pageTitle = __('login'); include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:440px;margin:40px auto">
    <h1><?= e(__('login')) ?></h1>
    <p class="muted"><?= e(__('login_lead')) ?></p>
    <form method="post" action="<?= e(url('/login')) ?>" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
            <label for="email"><?= e(__('email_or_username')) ?></label>
            <input id="email" name="email" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= e((string) old('email', '')) ?>">
        </div>
        <div class="field">
            <label for="password"><?= e(__('password')) ?></label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-block" type="submit"><?= e(__('login')) ?></button>
        <p style="margin:14px 0 0;display:flex;justify-content:space-between;font-size:.88rem">
            <a href="<?= e(url('/password/forgot')) ?>"><?= e(__('forgot_password')) ?></a>
            <a href="<?= e(url('/register')) ?>"><?= e(__('create_account')) ?></a>
        </p>
    </form>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
