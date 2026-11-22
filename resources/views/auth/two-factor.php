<?php $pageTitle = __('two_factor'); include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:420px;margin:48px auto">
    <h1><?= e(__('two_factor')) ?></h1>
    <p class="muted"><?= e(__('two_factor_lead')) ?></p>
    <form method="post" action="<?= e(url('/login/2fa')) ?>" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
        <div class="field">
            <label for="code"><?= e(__('auth_code')) ?></label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        </div>
        <button class="btn btn-block" type="submit"><?= e(__('verify')) ?></button>
        <p class="muted" style="font-size:.82rem;margin-top:12px"><?= e(__('recovery_hint')) ?></p>
    </form>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
