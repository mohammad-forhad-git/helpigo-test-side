<?php $id = (int) $user['id']; ?>
<a class="back-link" href="<?= e(url('/admin/customers')) ?>">← Customers</a>
<h1 class="page__title"><?= e($user['name']) ?> <small class="muted"><?= e($user['phone']) ?></small> <span class="badge"><?= e($user['status']) ?></span></h1>

<div class="grid-2">
    <div class="card">
        <h2>Money</h2>
        <p>Balance <strong class="<?= $balance < 0 ? 'danger-text' : '' ?>"><?= e(money($balance)) ?></strong> · usable credit <?= e(money($credit)) ?></p>
        <?php if ($credit > 0): ?>
            <form method="post" action="<?= e(url('/admin/money/customers/' . $id . '/refund')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <label>Refund sent to wallet (৳) <input type="text" name="amount" size="8" value="<?= e(App\Services\Money::toDecimal($credit)) ?>"></label>
                <input type="text" name="trx_id" placeholder="TrxID" size="12" required>
                <button class="btn btn--sm" type="submit">Record refund</button>
            </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/admin/money/customer/' . $id . '/adjust')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <label>Adjustment (৳, negative = customer owes) <input type="text" name="amount" size="8" required></label>
            <input type="text" name="reason" placeholder="Reason" required>
            <button class="btn btn--sm btn--ghost" type="submit">Add</button>
        </form>
        <ul class="small"><?php foreach ($rows as $r): ?><li><?= e(format_datetime($r['created_at'])) ?> · <?= e($r['type']) ?> <?= e(money_db($r['amount'])) ?> <?= $r['task_number'] ? '· ' . e($r['task_number']) : '' ?> <span class="muted"><?= e($r['note']) ?></span></li><?php endforeach; ?></ul>
    </div>
    <div class="card">
        <h2>Account</h2>
        <form method="post" action="<?= e(url('/admin/customers/' . $id . '/status')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <?php if ($user['status'] === 'active'): ?>
                <input type="hidden" name="status" value="suspended">
                <input type="text" name="reason" placeholder="Reason" required>
                <button class="btn btn--sm btn--danger" type="submit">Suspend</button>
            <?php else: ?>
                <input type="hidden" name="status" value="active">
                <button class="btn btn--sm" type="submit">Reactivate</button>
            <?php endif; ?>
        </form>
        <h3>Addresses</h3>
        <ul class="small"><?php foreach ($addresses as $a): ?><li><?= e($a['label']) ?> · <?= e($a['zone_name']) ?> · <?= e($a['address_line']) ?><?= $a['contact_name'] ? ' · for ' . e($a['contact_name'] . ' ' . $a['contact_phone']) : '' ?></li><?php endforeach; ?></ul>
        <p class="small muted">Joined <?= e(format_date($user['created_at'])) ?> · referral code <?= e($user['referral_code'] ?? '–') ?></p>
    </div>
</div>
<div class="card">
    <h2>Tasks</h2>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Task</th><th>Service</th><th>Status</th><th>Created</th></tr></thead>
        <tbody><?php foreach ($tasks as $t): ?><tr><td><a href="<?= e(url('/admin/tasks/' . $t['task_number'])) ?>"><?= e($t['task_number']) ?></a> <?= e($t['title']) ?></td><td><?= e($t['category_name']) ?></td><td><?= e($t['status']) ?></td><td><?= e(format_datetime($t['created_at'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div>
