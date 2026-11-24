<?php
$alerts = array_filter([
    $live['sos_open'] > 0 ? [$live['sos_open'] . ' SOS / safety report(s) open', '/admin/complaints', true] : null,
    $live['old_open_tasks'] > 0 ? [$live['old_open_tasks'] . ' open task(s) waiting over 30 minutes', '/admin/tasks?status=open', true] : null,
    $live['to_review'] > 0 ? [$live['to_review'] . ' task(s) flagged for review (hidden from helpers)', '/admin/tasks?review=1', true] : null,
    $live['advances_to_send'] > 0 ? [$live['advances_to_send'] . ' prepaid task(s) waiting for item money to the helper', '/admin/tasks?status=assigned', false] : null,
    $live['payments_pending'] > 0 ? [$live['payments_pending'] . ' payment(s) to verify', '/admin/payments', false] : null,
    $live['helpers_pending'] > 0 ? [$live['helpers_pending'] . ' helper profile(s) to review', '/admin/helpers?status=pending', false] : null,
    $live['payouts_pending'] > 0 ? [$live['payouts_pending'] . ' payout request(s)', '/admin/money/helpers', false] : null,
    $live['disputed'] > 0 ? [$live['disputed'] . ' disputed task(s)', '/admin/tasks?status=disputed', true] : null,
]);
?>
<h1 class="page__title">Dashboard</h1>
<?php if ($alerts === []): ?>
    <p class="note">Nothing needs attention right now.</p>
<?php else: ?>
    <ul class="alerts">
        <?php foreach ($alerts as [$text, $href, $urgent]): ?>
            <li class="<?= $urgent ? 'is-urgent' : '' ?>"><a href="<?= e(url($href)) ?>"><?= e($text) ?> →</a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat"><span class="stat__num"><?= (int) $live['open_tasks'] ?></span><span class="stat__label">Open tasks</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $live['active_tasks'] ?></span><span class="stat__label">Active tasks</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $live['helpers_online'] ?></span><span class="stat__label">Helpers online</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $today['completed'] ?></span><span class="stat__label">Completed today</span></div>
    <div class="stat"><span class="stat__num"><?= e(money($today['revenue'], false)) ?></span><span class="stat__label">Revenue today</span></div>
    <div class="stat"><span class="stat__num"><?= $today['median_wait'] !== null ? (int) $today['median_wait'] . ' min' : '–' ?></span><span class="stat__label">Median wait today</span></div>
</div>

<div class="grid-2">
    <div class="card">
        <h2>Helpers online by zone</h2>
        <table class="table">
            <thead><tr><th>Zone</th><th class="num">Online</th></tr></thead>
            <tbody><?php foreach ($zones as $z): ?><tr><td><?= e($z['zone']) ?></td><td class="num<?= (int) $z['online'] === 0 ? ' danger-text' : '' ?>"><?= (int) $z['online'] ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
    <div class="card">
        <h2>Oldest open tasks</h2>
        <?php if ($oldest === []): ?><p class="muted">None.</p><?php else: ?>
            <table class="table">
                <thead><tr><th>Task</th><th>Zone</th><th>Waiting</th></tr></thead>
                <tbody><?php foreach ($oldest as $t): ?><tr><td><a href="<?= e(url('/admin/tasks/' . $t['task_number'])) ?>"><?= e($t['title']) ?></a><br><small class="muted"><?= e($t['category_name']) ?></small></td><td><?= e($t['zone_name']) ?></td><td><?= (int) round((time() - strtotime($t['created_at'])) / 60) ?> min</td></tr><?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
