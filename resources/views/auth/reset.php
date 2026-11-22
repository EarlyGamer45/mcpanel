<?php $pageTitle = __('reset_password'); include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:440px;margin:48px auto">
    <h1><?= e(__('reset_password')) ?></h1>
    <form method="post" action="<?= e(url('/password/reset')) ?>" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
            <label for="password"><?= e(__('new_password')) ?></label>
            <input id="password" name="password" type="password" minlength="10" required autocomplete="new-password">
        </div>
        <button class="btn btn-block" type="submit"><?= e(__('reset_password')) ?></button>
    </form>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
