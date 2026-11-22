<?php $pageTitle = __('profile'); include __DIR__ . '/../partials/head.php'; ?>
<section>
    <h1><?= e(__('profile')) ?></h1>
    <div class="two-col">
        <form method="post" action="<?= e(url('/client/profile')) ?>" class="card">
            <?= csrf_field() ?>
            <h3><?= e(__('billing_details')) ?></h3>
            <div class="field-row">
                <div class="field">
                    <label for="username"><?= e(__('username')) ?></label>
                    <input id="username" name="username" value="<?= e($profile['name']) ?>" required>
                </div>
                <div class="field">
                    <label><?= e(__('email')) ?></label>
                    <input value="<?= e($profile['email']) ?>" readonly placeholder="<?= e(__('email_missing')) ?>">
                    <small class="muted"><a href="<?= e(url('/client/security#email')) ?>"><?= e(__('change_email')) ?></a></small>
                </div>
            </div>
            <div class="field-row">
                <div class="field"><label for="company"><?= e(__('company')) ?></label><input id="company" name="company" value="<?= e($profile['company']) ?>"></div>
                <div class="field"><label for="vat"><?= e(__('vat_number')) ?></label><input id="vat" name="vat" value="<?= e($profile['vat']) ?>"></div>
            </div>
            <div class="field">
                <label for="address"><?= e(__('address')) ?></label>
                <input id="address" name="address" value="<?= e($profile['address']) ?>">
            </div>
            <div class="field-row">
                <div class="field"><label for="postal_code"><?= e(__('postal_code')) ?></label><input id="postal_code" name="postal_code" value="<?= e($profile['postal_code']) ?>"></div>
                <div class="field"><label for="city"><?= e(__('city')) ?></label><input id="city" name="city" value="<?= e($profile['city']) ?>"></div>
            </div>
            <div class="field-row">
                <div class="field"><label for="country"><?= e(__('country')) ?></label>
                    <select id="country" name="country">
                        <?php foreach (['IT', 'DE', 'FR', 'ES', 'GB', 'US', 'CH'] as $country): ?>
                            <option value="<?= e($country) ?>" <?= $country === $profile['country'] ? 'selected' : '' ?>><?= e($country) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="phone"><?= e(__('phone')) ?></label><input id="phone" name="phone" value="<?= e($profile['phone']) ?>"></div>
            </div>
            <button class="btn" type="submit"><?= e(__('save')) ?></button>
        </form>

        <aside>
            <div class="card" style="margin-bottom:16px">
                <h3><?= e(__('password')) ?></h3>
                <p class="muted" style="font-size:.85rem"><?= e(__('change_password_profile_hint')) ?></p>
                <a class="btn btn-block" href="<?= e(url('/client/security#password')) ?>"><?= e(__('change_password')) ?></a>
            </div>
            <div class="card">
                <h3><?= e(__('privacy_tools')) ?></h3>
                <p class="muted" style="font-size:.85rem"><?= e(__('gdpr_help')) ?></p>
                <a class="btn btn-ghost btn-block" href="<?= e(url('/client/export')) ?>"><?= e(__('export_data')) ?></a>
            </div>
            <div class="card" style="margin-top:16px">
                <h3><?= e(__('delete_account')) ?></h3>
                <p class="muted" style="font-size:.85rem"><?= e(__('delete_help')) ?></p>
                <form method="post" action="<?= e(url('/client/delete')) ?>">
                    <?= csrf_field() ?>
                    <label class="checkbox" style="margin-bottom:10px">
                        <input type="checkbox" name="confirm" value="1" required>
                        <span><?= e(__('delete_confirm')) ?></span>
                    </label>
                    <button class="btn btn-danger btn-block btn-sm" type="submit"><?= e(__('delete_account')) ?></button>
                </form>
            </div>
        </aside>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
