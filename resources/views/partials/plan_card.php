<?php
/** @var array<string,mixed> $product */
$planMeta = \App\Core\Json::decode($product['meta_json'] ?? null);
$planFeatured = !empty($planMeta['featured']);
$planBadge = trim((string) ($planMeta['badge'] ?? ''));
$planFixed = \App\Domain\Catalog::planType($product) === 'fixed';
$planSpecs = $planFixed ? \App\Domain\Catalog::fixedSpecs($product) : null;
?>
<article class="card plan<?= $planFeatured ? ' plan-featured' : '' ?>">
    <?php if ($planFeatured): ?><span class="plan-ribbon"><?= e($planBadge !== '' ? $planBadge : __('most_popular')) ?></span><?php endif; ?>
    <div class="row-between">
        <span class="pill <?= $product['tier'] === 'premium' ? 'pill-accent' : ($product['tier'] === 'budget' ? 'pill-muted' : '') ?>"><?= e(__('tier_' . $product['tier'])) ?></span>
        <?php if (!$planFeatured && $planBadge !== ''): ?><span class="pill pill-muted"><?= e($planBadge) ?></span><?php endif; ?>
    </div>
    <h3 style="margin:14px 0 6px;font-size:1.25rem"><?= e((string) $product['name']) ?></h3>
    <p class="plan-desc"><?= e((string) $product['description']) ?></p>
    <?php if ($planFixed): ?>
        <p class="price"><?= e(money((int) $product['from_cents'])) ?> <small class="muted">/ <?= e(__('month_short')) ?></small></p>
        <ul class="plan-specs">
            <li><?= icon('layers') ?><?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $planSpecs['ram_mb'])) ?> RAM</li>
            <li><?= icon('cpu') ?><?= e(\App\Domain\PriceCalculator::humanResource('cpu', (int) $planSpecs['cpu_percent'])) ?></li>
            <li><?= icon('disk') ?><?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $planSpecs['disk_mb'])) ?> SSD</li>
            <li><?= icon('box') ?><?= e(ucfirst(\App\Domain\Catalog::softwareFor($product))) ?></li>
        </ul>
        <?php
        $cardSettings = \App\Domain\Catalog::displaySettings();
        $cardPacks = $cardSettings['show_packs'] ? ($product['addons'] ?? \App\Domain\Catalog::addons((int) $product['id'])) : [];
        usort($cardPacks, static fn (array $a, array $b): int => (\App\Domain\PriceCalculator::addonEffect($a) === null) <=> (\App\Domain\PriceCalculator::addonEffect($b) === null));
        ?>
        <form method="get" action="<?= e(url('/server/' . $product['slug'])) ?>" class="plan-order">
            <?php if ($cardPacks !== []): ?>
                <details class="plan-packs">
                    <summary><?= icon('puzzle') ?><?= e($cardSettings['packs_title']) ?></summary>
                    <div class="plan-packs-body">
                    <p class="muted plan-packs-lead"><strong><?= e(ucfirst(__('optional'))) ?></strong><?php if ($cardSettings['packs_lead'] !== ''): ?> · <?= e($cardSettings['packs_lead']) ?><?php endif; ?></p>
                    <?php foreach ($cardPacks as $cardPack): ?>
                        <label class="plan-pack">
                            <input type="checkbox" name="addons[]" value="<?= (int) $cardPack['id'] ?>">
                            <span><?= e((string) $cardPack['name']) ?></span>
                            <strong>+<?= e(money((int) $cardPack['price_cents'])) ?></strong>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
            <button class="btn btn-block<?= $planFeatured ? '' : ' btn-ghost' ?>" type="submit"><?= e(__('order_now')) ?> <?= icon('arrow') ?></button>
        </form>
    <?php else: ?>
        <p class="price"><small class="muted"><?= e(__('from')) ?></small> <?= e(money((int) $product['from_cents'])) ?> <small class="muted">/ <?= e(__('month_short')) ?></small></p>
        <?php $planOptions = $product['options'] ?? []; $range = static fn (string $r, string $suffix): string => isset($planOptions[$r]) ? \App\Domain\PriceCalculator::humanResource($r, (int) $planOptions[$r]['min_value']) . ' – ' . \App\Domain\PriceCalculator::humanResource($r, (int) $planOptions[$r]['max_value']) . $suffix : ''; ?>
        <ul class="plan-specs">
            <?php if ($range('ram', '') !== ''): ?><li><?= icon('layers') ?>RAM <?= e($range('ram', '')) ?></li><?php endif; ?>
            <?php if ($range('cpu', '') !== ''): ?><li><?= icon('cpu') ?>CPU <?= e($range('cpu', '')) ?></li><?php endif; ?>
            <?php if ($range('disk', '') !== ''): ?><li><?= icon('disk') ?>SSD <?= e($range('disk', '')) ?></li><?php endif; ?>
            <li><?= icon('settings') ?><?= e(__('custom_resources')) ?></li>
        </ul>
        <a class="btn btn-block<?= $planFeatured ? '' : ' btn-ghost' ?>" href="<?= e(url('/server/' . $product['slug'])) ?>"><?= e(__('configure')) ?> <?= icon('arrow') ?></a>
    <?php endif; ?>
</article>
