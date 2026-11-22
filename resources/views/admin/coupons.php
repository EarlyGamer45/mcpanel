<?php $pageTitle = __('coupons'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<?php $adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/'); ?>
<section>
    <h1><?= e(__('coupons')) ?></h1>
    <p class="muted"><?= e(__('admin_coupons_help')) ?></p>

    <div class="table-wrap" style="margin-top:16px">
        <table>
            <thead>
                <tr>
                    <th><?= e(__('coupon_code')) ?></th>
                    <th><?= e(__('coupon_type')) ?></th>
                    <th class="num"><?= e(__('uses')) ?></th>
                    <th><?= e(__('validity')) ?></th>
                    <th class="num"><?= e(__('per_user_limit')) ?></th>
                    <th><?= e(__('status')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($coupons as $coupon): ?>
                <?php
                $value = (string) $coupon['type'] === 'percent'
                    ? (int) $coupon['value'] . '%'
                    : money((int) $coupon['value']);
                $validity = trim((string) ($coupon['valid_from'] ?? '') . ' → ' . (string) ($coupon['valid_to'] ?? ''), ' →');
                ?>
                <tr>
                    <td class="mono"><strong><?= e((string) $coupon['code']) ?></strong></td>
                    <td><?= e(__((string) $coupon['type'])) ?> · <?= e($value) ?></td>
                    <td class="num">
                        <?= (int) $coupon['uses'] ?><?= (int) $coupon['max_uses'] > 0 ? ' / ' . (int) $coupon['max_uses'] : '' ?>
                        <br><small class="muted"><?= (int) $coupon['redemptions'] ?> <?= e(__('redemptions')) ?></small>
                    </td>
                    <td><small><?= e($validity !== '' ? $validity : __('no_expiry')) ?></small></td>
                    <td class="num"><?= (int) $coupon['per_user_limit'] ?></td>
                    <td><?= (int) $coupon['active'] === 1 ? '<span class="badge badge-ok">' . e(__('active')) . '</span>' : '<span class="badge badge-muted">' . e(__('inactive')) . '</span>' ?></td>
                    <td style="white-space:nowrap">
                        <form method="post" action="<?= e(url($adminBase . '/coupons/' . (int) $coupon['id'] . '/toggle')) ?>" style="display:inline">
                            <?= csrf_field() ?>
                            <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $coupon['active'] === 1 ? e(__('disable')) : e(__('enable')) ?></button>
                        </form>
                        <form method="post" action="<?= e(url($adminBase . '/coupons/' . (int) $coupon['id'] . '/delete')) ?>" style="display:inline" onsubmit="return confirm('<?= e(__('confirm_delete')) ?>')">
                            <?= csrf_field() ?>
                            <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($coupons === []): ?><tr><td colspan="7" class="muted"><?= e(__('no_coupons')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:32px"><?= e(__('create_coupon')) ?></h2>
    <form method="post" action="<?= e(url($adminBase . '/coupons')) ?>" class="card">
        <?= csrf_field() ?>
        <div class="grid grid-3">
            <div class="field">
                <label for="code"><?= e(__('coupon_code')) ?></label>
                <input id="code" name="code" required minlength="3" maxlength="40" placeholder="WELCOME10" pattern="[A-Za-z0-9_-]+">
            </div>
            <div class="field">
                <label for="type"><?= e(__('coupon_type')) ?></label>
                <select id="type" name="type">
                    <option value="percent"><?= e(__('percent')) ?></option>
                    <option value="fixed"><?= e(__('fixed')) ?></option>
                </select>
            </div>
            <div class="field">
                <label for="value"><?= e(__('coupon_value')) ?></label>
                <input id="value" name="value" required placeholder="10 · 5,50" inputmode="decimal">
                <small class="muted"><?= e(__('coupon_value_help')) ?></small>
            </div>
            <div class="field">
                <label for="valid_from"><?= e(__('valid_from')) ?></label>
                <input id="valid_from" name="valid_from" type="date">
            </div>
            <div class="field">
                <label for="valid_to"><?= e(__('valid_to')) ?></label>
                <input id="valid_to" name="valid_to" type="date">
            </div>
            <div class="field">
                <label for="applies_to"><?= e(__('applies_to')) ?></label>
                <input id="applies_to" name="applies_to" placeholder="1, 2, 3">
                <small class="muted"><?= e(__('applies_to_help')) ?></small>
            </div>
            <div class="field">
                <label for="max_uses"><?= e(__('max_uses')) ?></label>
                <input id="max_uses" name="max_uses" type="number" min="0" value="0">
                <small class="muted">0 = <?= e(__('unlimited')) ?></small>
            </div>
            <div class="field">
                <label for="per_user_limit"><?= e(__('per_user_limit')) ?></label>
                <input id="per_user_limit" name="per_user_limit" type="number" min="1" value="1">
            </div>
            <div class="field">
                <label for="first_purchase_only" style="display:flex;gap:8px;align-items:center">
                    <input id="first_purchase_only" name="first_purchase_only" type="checkbox" value="1">
                    <?= e(__('first_purchase_only')) ?>
                </label>
            </div>
        </div>
        <button class="btn" type="submit"><?= e(__('create_coupon')) ?></button>
    </form>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
