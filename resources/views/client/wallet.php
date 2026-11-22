<?php $pageTitle = __('wallet'); include __DIR__ . '/../partials/head.php'; ?>
<section>
    <h1><?= e(__('wallet')) ?></h1>
    <div class="grid grid-2">
        <div class="card">
            <span class="muted"><?= e(__('available_balance')) ?></span>
            <p class="price"><?= e(money((int) $balance)) ?></p>
            <p class="muted" style="font-size:.85rem"><?= e(__('wallet_help')) ?></p>
        </div>
        <div class="card">
            <h3><?= e(__('recharge')) ?></h3>
            <p class="muted" style="font-size:.85rem"><?= e(__('recharge_help')) ?></p>
            <?php if (!empty($bank['iban'])): ?>
                <p class="mono" style="font-size:.85rem">IBAN <?= e((string) $bank['iban']) ?><br><?= e((string) ($bank['holder'] ?? '')) ?></p>
            <?php else: ?>
                <div class="alert"><?= e(__('bank_not_configured')) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/client/wallet/topup')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="amount"><?= e(__('amount_eur')) ?></label>
                    <div class="input-money"><input id="amount" name="amount" inputmode="decimal" data-money value="20,00" required></div>
                    <span class="field-help"><?= e(__('topup_limits', ['min' => money(500), 'max' => money(50000)])) ?></span>
                </div>
                <button class="btn btn-block" type="submit"><?= e(__('create_topup')) ?></button>
            </form>
        </div>
    </div>
</section>

<section>
    <h2><?= e(__('pending_topups')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('reference')) ?></th><th class="num"><?= e(__('amount')) ?></th><th><?= e(__('date')) ?></th><th><?= e(__('status')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($topups as $topup): ?>
                <tr>
                    <td class="mono"><?= e((string) $topup['reference']) ?></td>
                    <td class="num"><?= e(money((int) $topup['amount_cents'])) ?></td>
                    <td><?= e(fmt_date((string) $topup['created_at'], 'd/m/Y')) ?></td>
                    <td><?= status_badge((string) $topup['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($topups === []): ?><tr><td colspan="4" class="muted"><?= e(__('no_topups')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section>
    <h2><?= e(__('transactions')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('date')) ?></th><th><?= e(__('type')) ?></th><th><?= e(__('reference')) ?></th><th class="num"><?= e(__('amount')) ?></th><th class="num"><?= e(__('balance')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($transactions as $transaction): ?>
                <tr>
                    <td><?= e(fmt_date((string) $transaction['created_at'])) ?></td>
                    <td><?= e(__('tx_' . (string) $transaction['type'])) ?></td>
                    <td class="mono"><?= e((string) ($transaction['reference'] ?? '—')) ?></td>
                    <td class="num" style="color:<?= (int) $transaction['amount_cents'] >= 0 ? 'var(--brand)' : 'var(--danger)' ?>"><?= e(money((int) $transaction['amount_cents'])) ?></td>
                    <td class="num"><?= e(money((int) $transaction['balance_after_cents'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($transactions === []): ?><tr><td colspan="5" class="muted"><?= e(__('no_transactions')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
