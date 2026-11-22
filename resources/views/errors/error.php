<?php $pageTitle = __('error') . ' ' . $status; include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:620px;margin:60px auto;text-align:center">
    <p class="pill pill-muted"><?= (int) $status ?></p>
    <h1><?= $status === 404 ? e(__('error_404_title')) : e(__('error_title')) ?></h1>
    <p class="muted"><?= $status === 404 ? e(__('error_404_body')) : e(__('error_body')) ?></p>
    <?php if ($message !== ''): ?>
        <div class="alert" style="text-align:left"><code><?= e($message) ?></code></div>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('/')) ?>"><?= e(__('back_home')) ?></a>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
