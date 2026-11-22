<?php $pageTitle = (string) $service['name']; include __DIR__ . '/../partials/head.php'; ?>
<section>
    <p class="muted"><a href="<?= e(url('/client')) ?>"><?= e(__('dashboard')) ?></a> / <?= e((string) $service['name']) ?></p>
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center">
        <h1 style="margin:0"><?= e((string) $service['name']) ?></h1>
        <?= status_badge((string) $service['status']) ?>
    </div>

    <div class="grid grid-4" style="margin:20px 0">
        <div class="stat"><span>RAM</span><strong><?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $service['ram_mb'])) ?></strong></div>
        <div class="stat"><span>CPU</span><strong><?= (int) $service['cpu_percent'] ?>%</strong></div>
        <div class="stat"><span><?= e(__('disk')) ?></span><strong><?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $service['disk_mb'])) ?></strong></div>
        <div class="stat"><span><?= e(__('renewal')) ?></span><strong style="font-size:1rem"><?= e(fmt_date((string) $service['next_due_at'], 'd/m/Y')) ?></strong></div>
    </div>

    <div class="two-col">
        <div>
            <div class="card">
                <h3><?= e(__('connection')) ?></h3>
                <p class="mono"><?= $service['ip'] ? e($service['ip'] . ':' . (int) $service['port']) : e(__('not_assigned')) ?></p>
                <p class="muted" style="font-size:.88rem">
                    <?= e(ucfirst((string) ($service['config']['software'] ?? 'paper'))) ?> <?= e((string) ($service['config']['version'] ?? '')) ?>
                    <?php if (!empty($service['external_identifier'])): ?>
                        · <a href="<?= e(rtrim((string) config('pterodactyl.url', ''), '/') . '/server/' . $service['external_identifier']) ?>" target="_blank" rel="noopener"><?= e(__('open_in_panel')) ?></a>
                    <?php endif; ?>
                </p>
            </div>

            <div class="card" style="margin-top:18px">
                <h3><?= e(__('power_actions')) ?></h3>
                <?php if ($resources !== null): ?>
                    <?php $state = (string) ($resources['current_state'] ?? 'unknown'); ?>
                    <p class="muted"><?= e(__('current_state')) ?>: <strong><?= e($state) ?></strong></p>
                    <?php $usage = (array) ($resources['resources'] ?? []); ?>
                    <?php if ($usage !== []): ?>
                        <p class="muted" style="font-size:.85rem">
                            RAM: <?= (int) round(((int) ($usage['memory_bytes'] ?? 0)) / 1048576) ?> MB ·
                            CPU: <?= (float) ($usage['cpu_absolute'] ?? 0) ?>% ·
                            <?= e(__('uptime')) ?>: <?= (int) round(((int) ($usage['uptime'] ?? 0)) / 60000) ?> min
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="hero-actions">
                    <?php foreach (['start', 'restart', 'stop', 'kill'] as $signal): ?>
                        <form method="post" action="<?= e(url('/client/service/' . (int) $service['id'] . '/power')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="signal" value="<?= e($signal) ?>">
                            <button class="btn <?= $signal === 'kill' ? 'btn-danger' : ($signal === 'start' ? '' : 'btn-ghost') ?> btn-sm" type="submit"><?= e(ucfirst($signal)) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <p class="muted" style="font-size:.8rem;margin-top:10px"><?= e(__('power_note')) ?></p>
            </div>

            <div class="card" style="margin-top:18px" id="server-console"
                 data-token-url="<?= e(url('/client/service/' . (int) $service['id'] . '/console-token')) ?>"
                 data-connect-text="<?= e(__('console_connect')) ?>"
                 data-disconnect-text="<?= e(__('console_disconnect')) ?>"
                 data-connecting-text="<?= e(__('console_connecting')) ?>"
                 data-connected-text="<?= e(__('console_connected')) ?>"
                 data-disconnected-text="<?= e(__('console_disconnected')) ?>"
                 data-lost-text="<?= e(__('console_lost')) ?>"
                 data-error-text="<?= e(__('console_unavailable')) ?>">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
                    <h3 style="margin:0"><?= e(__('console')) ?></h3>
                    <button class="btn btn-sm btn-secondary" type="button" data-console-toggle><?= e(__('console_connect')) ?></button>
                </div>
                <p class="muted" style="font-size:.8rem;margin:8px 0" data-console-status></p>
                <pre data-console-output style="height:260px;overflow:auto;background:#0b0f14;color:#c7f9cc;padding:12px;border-radius:8px;font-size:.78rem;line-height:1.45;white-space:pre-wrap;word-break:break-word"></pre>
                <form data-console-form hidden style="display:flex;gap:8px;margin-top:10px">
                    <input data-console-input type="text" maxlength="1000" autocomplete="off" placeholder="<?= e(__('console_placeholder')) ?>" style="flex:1">
                    <button class="btn btn-sm" type="submit"><?= e(__('send')) ?></button>
                </form>
                <p class="muted" style="font-size:.78rem;margin-top:8px"><?= e(__('console_hint')) ?></p>
            </div>

            <div class="card" style="margin-top:18px">
                <h3><?= e(__('service_history')) ?></h3>
                <ul class="clean" style="font-size:.88rem">
                    <?php foreach ($events as $event): ?>
                        <li>
                            <?= e(fmt_date((string) $event['created_at'])) ?> — <?= e((string) ($event['from_state'] ?? '—')) ?> → <?= e((string) $event['to_state']) ?>
                            <?php if (!empty($event['reason'])): ?><br><small class="muted"><?= e((string) $event['reason']) ?></small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <aside>
            <div class="card summary">
                <h3><?= e(__('subscription')) ?></h3>
                <div class="row"><span><?= e(__('price')) ?></span><span><?= e(money((int) $service['price_cents'])) ?></span></div>
                <div class="row"><span><?= e(__('cycle')) ?></span><span><?= e(__((string) $service['cycle'])) ?></span></div>
                <div class="row"><span><?= e(__('auto_renew')) ?></span><span><?= (int) $service['auto_renew'] === 1 ? e(__('enabled')) : e(__('disabled')) ?></span></div>
                <a class="btn btn-ghost btn-block" style="margin-top:14px" href="<?= e(url('/client/tickets')) ?>"><?= e(__('get_support')) ?></a>
            </div>

            <div class="card" style="margin-top:18px">
                <h3><?= e(__('cancel_service')) ?></h3>
                <p class="muted" style="font-size:.85rem"><?= e(__('cancel_help')) ?></p>
                <form method="post" action="<?= e(url('/client/service/' . (int) $service['id'] . '/cancel')) ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="reason"><?= e(__('reason')) ?></label>
                        <input id="reason" name="reason" required>
                    </div>
                    <button class="btn btn-danger btn-block btn-sm" type="submit" onclick="return confirm('<?= e(__('confirm_cancel')) ?>')"><?= e(__('request_cancellation')) ?></button>
                </form>
            </div>
        </aside>
    </div>
</section>
<script src="<?= e(asset('console.js')) ?>" defer></script>
<?php include __DIR__ . '/../partials/foot.php'; ?>
