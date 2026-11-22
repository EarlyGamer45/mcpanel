<?php $pageTitle = __('maintenance'); include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:600px;margin:70px auto;text-align:center">
    <p class="pill"><?= e(__('maintenance')) ?></p>
    <h1><?= e(__('maintenance_title')) ?></h1>
    <p class="muted"><?= e(__('maintenance_body')) ?></p>
    <a class="btn btn-ghost" href="<?= e(url('/status')) ?>"><?= e(__('status_page')) ?></a>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
