<?php $pageTitle = __('catalog'); include __DIR__ . '/partials/head.php'; ?>

<section>
    <span class="eyebrow"><?= e(__('catalog')) ?></span>
    <h1><?= e(isset($currentCategory) ? (string) $currentCategory['name'] : __('catalog')) ?></h1>
    <p class="lead"><?= e(isset($currentCategory) ? (string) $currentCategory['description'] : __('catalog_lead')) ?></p>

    <div class="tabs" style="margin:22px 0 26px">
        <a href="<?= e(url('/catalog')) ?>" class="<?= !isset($currentCategory) ? 'active' : '' ?>"><?= e(__('all')) ?></a>
        <?php foreach ($categories as $category): ?>
            <a href="<?= e(url('/catalog/' . $category['slug'])) ?>" class="<?= isset($currentCategory) && $currentCategory['id'] === $category['id'] ? 'active' : '' ?>"><?= e((string) $category['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php
    $catalogSettings = \App\Domain\Catalog::displaySettings();
    $groups = [
        'fixed' => array_values(array_filter($products, static fn (array $p): bool => ($p['plan_type'] ?? 'custom') === 'fixed')),
        'custom' => array_values(array_filter($products, static fn (array $p): bool => ($p['plan_type'] ?? 'custom') !== 'fixed')),
    ];
    $order = $catalogSettings['order'] === 'custom_first' ? ['custom', 'fixed'] : ['fixed', 'custom'];
    $shown = 0;
    ?>
    <?php foreach ($order as $group): ?>
        <?php if (!$catalogSettings['show_' . $group] || $groups[$group] === []) { continue; } $shown++; ?>
        <div class="catalog-group" id="<?= e($group === 'fixed' ? 'piani' : 'configuratore') ?>">
            <div class="section-head">
                <div>
                    <h2><?= e($catalogSettings[$group . '_title']) ?></h2>
                    <?php if ($catalogSettings[$group . '_lead'] !== ''): ?><p class="muted"><?= e($catalogSettings[$group . '_lead']) ?></p><?php endif; ?>
                </div>
            </div>
            <div class="grid grid-3">
                <?php foreach ($groups[$group] as $product): ?>
                    <?php include __DIR__ . '/partials/plan_card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?>
        <p class="muted"><?= e(__('no_products')) ?></p>
    <?php endif; ?>
</section>

<section class="two-col">
    <div>
        <h2><?= e(__('compare_tiers')) ?></h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th><?= e(__('feature')) ?></th><th><?= e(__('tier_budget')) ?></th><th><?= e(__('tier_standard')) ?></th><th><?= e(__('tier_premium')) ?></th></tr>
                </thead>
                <tbody>
                <tr><td><?= e(__('cpu_priority')) ?></td><td>—</td><td>+</td><td>++</td></tr>
                <tr><td><?= e(__('included_backups')) ?></td><td>1</td><td>3</td><td>5</td></tr>
                <tr><td><?= e(__('support')) ?></td><td><?= e(__('standard')) ?></td><td><?= e(__('priority')) ?></td><td><?= e(__('dedicated')) ?></td></tr>
                <tr><td><?= e(__('modpack_ready')) ?></td><td>—</td><td>+</td><td>+</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <aside class="card">
        <span class="feature-icon" style="margin-bottom:12px"><?= icon('chat') ?></span>
        <h3><?= e(__('need_help')) ?></h3>
        <p class="muted"><?= e(__('need_help_body')) ?></p>
        <a class="btn btn-ghost btn-block" href="<?= e(url('/knowledge')) ?>"><?= e(__('knowledge_base')) ?></a>
    </aside>
</section>

<?php include __DIR__ . '/partials/foot.php'; ?>
