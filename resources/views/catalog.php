<?php $pageTitle = __('catalog'); include __DIR__ . '/partials/head.php'; ?>
<?php
$catalogSettings = \App\Domain\Catalog::displaySettings();
$groups = [
    'fixed' => array_values(array_filter($products, static fn (array $p): bool => ($p['plan_type'] ?? 'custom') === 'fixed')),
    'custom' => array_values(array_filter($products, static fn (array $p): bool => ($p['plan_type'] ?? 'custom') !== 'fixed')),
];
$order = $catalogSettings['order'] === 'custom_first' ? ['custom', 'fixed'] : ['fixed', 'custom'];
$shown = 0;
?>

<section class="catalog-page">
    <div class="catalog-hero">
        <div>
            <span class="eyebrow"><?= e(__('catalog')) ?></span>
            <h1><?= e(isset($currentCategory) ? (string) $currentCategory['name'] : __('catalog')) ?></h1>
            <p class="lead"><?= e(isset($currentCategory) ? (string) $currentCategory['description'] : __('catalog_lead')) ?></p>
        </div>
        <?php if (count($categories) > 1): ?>
            <nav class="tabs catalog-tabs">
                <a href="<?= e(url('/catalog')) ?>" class="<?= !isset($currentCategory) ? 'active' : '' ?>"><?= e(__('all')) ?></a>
                <?php foreach ($categories as $category): ?>
                    <a href="<?= e(url('/catalog/' . $category['slug'])) ?>" class="<?= isset($currentCategory) && (int) $currentCategory['id'] === (int) $category['id'] ? 'active' : '' ?>"><?= e((string) $category['name']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>

    <?php foreach ($order as $group): ?>
        <?php if (!$catalogSettings['show_' . $group] || $groups[$group] === []) { continue; } $shown++; ?>
        <div class="catalog-group" id="<?= e($group === 'fixed' ? 'packages' : 'configurator') ?>">
            <div class="section-head">
                <div>
                    <h2><?= e($catalogSettings[$group . '_title']) ?></h2>
                    <?php if ($catalogSettings[$group . '_lead'] !== ''): ?><p class="muted"><?= e($catalogSettings[$group . '_lead']) ?></p><?php endif; ?>
                </div>
            </div>
            <?php if ($group === 'fixed'): ?>
                <div class="plan-grid">
                    <?php foreach ($groups['fixed'] as $product): ?>
                        <?php include __DIR__ . '/partials/plan_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="configurator-list">
                    <?php foreach ($groups['custom'] as $product): ?>
                        <?php
                        $planOptions = $product['options'] ?? [];
                        $range = static fn (string $r): string => isset($planOptions[$r])
                            ? \App\Domain\PriceCalculator::humanResource($r, (int) $planOptions[$r]['min_value']) . ' – ' . \App\Domain\PriceCalculator::humanResource($r, (int) $planOptions[$r]['max_value'])
                            : '';
                        ?>
                        <article class="card configurator-card">
                            <div class="configurator-main">
                                <span class="feature-icon"><?= icon('settings') ?></span>
                                <div>
                                    <h3><?= e((string) $product['name']) ?></h3>
                                    <p class="muted"><?= e((string) $product['description']) ?></p>
                                </div>
                            </div>
                            <ul class="configurator-ranges">
                                <?php foreach (['cpu' => 'CPU', 'ram' => 'RAM', 'disk' => 'SSD'] as $resource => $label): ?>
                                    <?php if ($range($resource) !== ''): ?><li><small class="muted"><?= e($label) ?></small><strong><?= e($range($resource)) ?></strong></li><?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <div class="configurator-cta">
                                <p class="price"><small class="muted"><?= e(__('from')) ?></small> <?= e(money((int) $product['from_cents'])) ?> <small class="muted">/ <?= e(__('month_short')) ?></small></p>
                                <a class="btn" href="<?= e(url('/server/' . $product['slug'])) ?>"><?= icon('settings') ?><?= e(__('configure')) ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($shown === 0): ?>
        <p class="muted"><?= e(__('no_products')) ?></p>
    <?php endif; ?>

    <div class="card card-flat catalog-help">
        <span class="feature-icon"><?= icon('chat') ?></span>
        <div>
            <strong><?= e(__('need_help')) ?></strong>
            <p class="muted"><?= e(__('need_help_body')) ?></p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/knowledge')) ?>"><?= e(__('knowledge_base')) ?></a>
    </div>
</section>

<?php include __DIR__ . '/partials/foot.php'; ?>
