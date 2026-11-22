<?php $pageTitle = __('forgot_password'); include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:440px;margin:48px auto">
    <h1><?= e(__('forgot_password')) ?></h1>
    <p class="muted"><?= e(__('forgot_lead')) ?></p>
    <form method="post" action="<?= e(url('/password/forgot')) ?>" class="card">
        <?= csrf_field() ?>
        <div class="field">
            <label for="email"><?= e(__('email')) ?></label>
            <input id="email" name="email" type="email" required autocomplete="email">
        </div>
        <button class="btn btn-block" type="submit"><?= e(__('send_reset_link')) ?></button>
    </form>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
