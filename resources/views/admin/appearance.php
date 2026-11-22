<?php $pageTitle = __('appearance'); include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$a = $appearance;
$choice = static function (string $name, string $value, string $label, string $current, string $hint = ''): string {
    return '<label class="choice"><input type="radio" name="' . e($name) . '" value="' . e($value) . '"' . ($value === $current ? ' checked' : '') . '>'
        . '<span>' . e($label) . ($hint !== '' ? '<small class="muted">' . e($hint) . '</small>' : '') . '</span></label>';
};
$switch = static function (string $name, bool $checked, string $label): string {
    return '<label class="switch"><input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
};
?>
<div class="page-head">
    <div>
        <h1><?= e(__('appearance')) ?></h1>
        <p><?= e(__('appearance_lead')) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('external') ?><?= e(__('view_site')) ?></a>
</div>

<?php include __DIR__ . '/../partials/license_lock.php'; ?>
<fieldset class="plain" <?= $licenseLocked ? 'disabled' : '' ?>>
<div class="settings-layout">
    <nav class="settings-menu" aria-label="<?= e(__('appearance')) ?>">
        <a href="#colors"><?= icon('palette') ?><?= e(__('appearance_colors')) ?></a>
        <a href="#identity"><?= icon('tag') ?><?= e(__('appearance_identity')) ?></a>
        <a href="#layout"><?= icon('layers') ?><?= e(__('appearance_layout')) ?></a>
        <a href="#home"><?= icon('home') ?><?= e(__('appearance_home')) ?></a>
        <a href="#advanced"><?= icon('settings') ?><?= e(__('appearance_advanced')) ?></a>
    </nav>

    <form method="post" action="<?= e(url($adminBase . '/appearance')) ?>" enctype="multipart/form-data" data-appearance-form class="stack">
        <?= csrf_field() ?>

        <div class="card settings-section" id="colors">
            <h3 class="card-title"><?= icon('palette') ?><?= e(__('appearance_colors')) ?></h3>
            <div class="field">
                <label><?= e(__('theme_mode')) ?></label>
                <div class="choices">
                    <?= $choice('theme_mode', 'dark', __('theme_dark'), $a['theme_mode'], __('theme_dark_hint')) ?>
                    <?= $choice('theme_mode', 'light', __('theme_light'), $a['theme_mode'], __('theme_light_hint')) ?>
                    <?= $choice('theme_mode', 'auto', __('theme_auto'), $a['theme_mode'], __('theme_auto_hint')) ?>
                </div>
            </div>
            <div class="field"><?= $switch('theme_toggle', $a['theme_toggle'], __('theme_toggle_label')) ?></div>
            <div class="field-row">
                <div class="field">
                    <label for="brand_color"><?= e(__('brand_color')) ?></label>
                    <div style="display:grid;grid-template-columns:56px 1fr;gap:8px">
                        <input type="color" id="brand_color" name="brand_color" value="<?= e($a['brand_color']) ?>">
                        <input type="text" value="<?= e($a['brand_color']) ?>" data-color-text="brand_color" maxlength="7" aria-label="<?= e(__('brand_color')) ?> HEX" class="mono">
                    </div>
                    <span class="field-help"><?= e(__('brand_color_help')) ?></span>
                </div>
                <div class="field">
                    <label for="accent_color"><?= e(__('accent_color')) ?></label>
                    <div style="display:grid;grid-template-columns:56px 1fr;gap:8px">
                        <input type="color" id="accent_color" name="accent_color" value="<?= e($a['accent_color']) ?>">
                        <input type="text" value="<?= e($a['accent_color']) ?>" data-color-text="accent_color" maxlength="7" aria-label="<?= e(__('accent_color')) ?> HEX" class="mono">
                    </div>
                    <span class="field-help"><?= e(__('accent_color_help')) ?></span>
                </div>
            </div>
            <div class="field">
                <label><?= e(__('color_presets')) ?></label>
                <div style="display:flex;gap:8px;flex-wrap:wrap" data-presets>
                    <?php foreach (['#2563eb' => '#1d4ed8', '#22c55e' => '#15803d', '#3b82f6' => '#a855f7', '#f97316' => '#facc15', '#ef4444' => '#f97316', '#8b5cf6' => '#ec4899', '#14b8a6' => '#22d3ee', '#eab308' => '#84cc16', '#64748b' => '#0ea5e9'] as $presetBrand => $presetAccent): ?>
                        <button type="button" class="icon-btn" data-preset-brand="<?= e($presetBrand) ?>" data-preset-accent="<?= e($presetAccent) ?>" title="<?= e($presetBrand) ?>" style="width:46px">
                            <span class="swatch" style="background:linear-gradient(135deg, <?= e($presetBrand) ?> 50%, <?= e($presetAccent) ?> 50%)"></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="preview-box" data-appearance-preview>
                <div class="preview-bar">
                    <span class="brand"><span class="brand-mark"><span></span></span><?= e((string) setting('brand_name', config('app.name'))) ?></span>
                    <span class="pill"><?= e(__('preview')) ?></span>
                </div>
                <div class="preview-body">
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <span class="btn btn-sm"><?= e(__('choose_plan')) ?></span>
                        <span class="btn btn-sm btn-ghost"><?= e(__('read_guides')) ?></span>
                        <span class="btn btn-sm btn-accent"><?= e(__('accent_color')) ?></span>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <span class="badge badge-ok"><?= e(__('status_active')) ?></span>
                        <span class="badge badge-warn"><?= e(__('pending')) ?></span>
                        <span class="pill"><?= e(__('tier_standard')) ?></span>
                        <a href="#colors"><?= e(__('example_link')) ?></a>
                    </div>
                    <div class="progress"><span style="width:62%"></span></div>
                </div>
            </div>
        </div>

        <div class="card settings-section" id="identity">
            <h3 class="card-title"><?= icon('tag') ?><?= e(__('appearance_identity')) ?></h3>
            <div class="field-row">
                <div class="field">
                    <label for="logo"><?= e(__('logo')) ?></label>
                    <div class="image-preview">
                        <?php if ($a['logo_data'] !== ''): ?>
                            <img src="<?= e($a['logo_data']) ?>" alt="">
                        <?php else: ?>
                            <span class="muted"><?= e(__('no_logo')) ?></span>
                        <?php endif; ?>
                    </div>
                    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" style="margin-top:8px">
                    <span class="field-help"><?= e(__('logo_help', ['kb' => (string) intdiv(\App\Domain\Theme::MAX_IMAGE_BYTES, 1024)])) ?></span>
                    <?php if ($a['logo_data'] !== ''): ?><label class="checkbox" style="margin-top:8px"><input type="checkbox" name="remove_logo" value="1"><span><?= e(__('remove_logo')) ?></span></label><?php endif; ?>
                </div>
                <div class="field">
                    <label for="favicon"><?= e(__('favicon')) ?></label>
                    <div class="image-preview">
                        <?php if ($a['favicon_data'] !== ''): ?>
                            <img src="<?= e($a['favicon_data']) ?>" alt="" style="max-height:32px">
                        <?php else: ?>
                            <span class="muted"><?= e(__('favicon_auto')) ?></span>
                        <?php endif; ?>
                    </div>
                    <input type="file" id="favicon" name="favicon" accept="image/png,image/x-icon,image/gif,image/webp" style="margin-top:8px">
                    <span class="field-help"><?= e(__('favicon_help')) ?></span>
                    <?php if ($a['favicon_data'] !== ''): ?><label class="checkbox" style="margin-top:8px"><input type="checkbox" name="remove_favicon" value="1"><span><?= e(__('remove_favicon')) ?></span></label><?php endif; ?>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="brand_name"><?= e(__('brand_name')) ?></label>
                    <input id="brand_name" name="brand_name" value="<?= e((string) setting('brand_name', config('app.name'))) ?>">
                </div>
                <div class="field">
                    <label for="logo_height"><?= e(__('logo_height')) ?></label>
                    <div class="input-suffix"><input id="logo_height" name="logo_height" type="number" min="20" max="80" value="<?= (int) $a['logo_height'] ?>"><span>px</span></div>
                </div>
            </div>
            <?= $switch('show_brand_name', $a['show_brand_name'], __('show_brand_name')) ?>
        </div>

        <div class="card settings-section" id="layout">
            <h3 class="card-title"><?= icon('layers') ?><?= e(__('appearance_layout')) ?></h3>
            <div class="field">
                <label><?= e(__('font_family')) ?></label>
                <div class="choices">
                    <?php foreach (\App\Domain\Theme::FONTS as $font): ?>
                        <?= $choice('font_family', $font, __('font_' . $font), $a['font_family']) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <label><?= e(__('corner_radius')) ?></label>
                <div class="choices">
                    <?php foreach (\App\Domain\Theme::RADII as $radius): ?>
                        <?= $choice('radius', $radius, __('radius_' . $radius), $a['radius']) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <label><?= e(__('density')) ?></label>
                <div class="choices">
                    <?php foreach (\App\Domain\Theme::DENSITIES as $density): ?>
                        <?= $choice('density', $density, __('density_' . $density), $a['density']) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label><?= e(__('background_style')) ?></label>
                    <div class="choices">
                        <?php foreach (\App\Domain\Theme::BACKGROUNDS as $background): ?>
                            <?= $choice('background_style', $background, __('background_' . $background), $a['background_style']) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field">
                    <label><?= e(__('layout_width')) ?></label>
                    <div class="choices">
                        <?php foreach (\App\Domain\Theme::WIDTHS as $width): ?>
                            <?= $choice('layout_width', $width, __('width_' . $width), $a['layout_width']) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card settings-section" id="home">
            <h3 class="card-title"><?= icon('home') ?><?= e(__('appearance_home')) ?></h3>
            <p class="card-hint"><?= e(__('appearance_home_hint')) ?></p>
            <div class="field-row">
                <div class="field">
                    <label for="hero_badge"><?= e(__('hero_badge_label')) ?></label>
                    <input id="hero_badge" name="hero_badge" maxlength="80" value="<?= e($a['hero_badge']) ?>" placeholder="<?= e(__('hero_badge')) ?>">
                </div>
                <div class="field">
                    <label for="hero_cta"><?= e(__('hero_cta_label')) ?></label>
                    <input id="hero_cta" name="hero_cta" maxlength="60" value="<?= e($a['hero_cta']) ?>" placeholder="<?= e(__('choose_plan')) ?>">
                </div>
            </div>
            <div class="field">
                <label for="hero_title"><?= e(__('hero_title_label')) ?></label>
                <input id="hero_title" name="hero_title" maxlength="160" value="<?= e($a['hero_title']) ?>" placeholder="<?= e(__('hero_title')) ?>">
            </div>
            <div class="field">
                <label for="hero_lead"><?= e(__('hero_lead_label')) ?></label>
                <textarea id="hero_lead" name="hero_lead" maxlength="500" style="min-height:80px" placeholder="<?= e(__('hero_lead')) ?>"><?= e($a['hero_lead']) ?></textarea>
            </div>
            <div class="field">
                <label><?= e(__('home_sections')) ?></label>
                <div class="grid grid-3" style="gap:8px">
                    <?php foreach (\App\Domain\Theme::HOME_SECTIONS as $section): ?>
                        <label class="switch" style="padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface-2)">
                            <input type="checkbox" name="home_sections[]" value="<?= e($section) ?>" <?= in_array($section, $a['home_sections'], true) ? 'checked' : '' ?>>
                            <span><?= e(__('home_section_' . $section)) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="topbar_text"><?= e(__('topbar_text')) ?></label>
                    <input id="topbar_text" name="topbar_text" maxlength="200" value="<?= e($a['topbar_text']) ?>" placeholder="<?= e(__('topbar_text_placeholder')) ?>">
                    <span class="field-help"><?= e(__('topbar_text_help')) ?></span>
                </div>
                <div class="field">
                    <label for="topbar_link"><?= e(__('topbar_link')) ?></label>
                    <input id="topbar_link" name="topbar_link" maxlength="300" value="<?= e($a['topbar_link']) ?>" placeholder="/catalog">
                </div>
            </div>
            <div class="field">
                <label for="footer_text"><?= e(__('footer_text')) ?></label>
                <input id="footer_text" name="footer_text" maxlength="300" value="<?= e($a['footer_text']) ?>" placeholder="<?= e(__('footer_tagline')) ?>">
            </div>
        </div>

        <div class="card settings-section" id="advanced">
            <h3 class="card-title"><?= icon('settings') ?><?= e(__('appearance_advanced')) ?></h3>
            <div class="field">
                <label for="custom_css"><?= e(__('custom_css')) ?></label>
                <textarea id="custom_css" name="custom_css" class="mono" spellcheck="false" style="min-height:160px" placeholder=".hero h1 { text-transform: uppercase; }"><?= e($a['custom_css']) ?></textarea>
                <span class="field-help"><?= e(__('custom_css_help')) ?></span>
            </div>
            <label class="checkbox"><input type="checkbox" name="reset_appearance" value="1"><span><?= e(__('reset_appearance')) ?></span></label>
        </div>

        <div class="sticky-actions">
            <button class="btn btn-lg" type="submit"><?= icon('check') ?><?= e(__('save')) ?></button>
        </div>
    </form>
</div>
</fieldset>
<?php include __DIR__ . '/../partials/foot.php'; ?>
