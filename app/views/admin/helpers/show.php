<?php $id = (int) $p['user_id']; ?>
<a class="back-link" href="<?= e(url('/admin/helpers')) ?>">← Helpers</a>
<h1 class="page__title"><?= e($p['name']) ?> <span class="badge badge--h-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></h1>
<?php if ($p['review_note']): ?><p class="note">Last note: <?= e($p['review_note']) ?></p><?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h2>Identity</h2>
        <dl class="details">
            <div><dt>Phone</dt><dd><?= e($p['phone']) ?> <?= $p['phone_verified_at'] ? '✓ verified' : '<span class="danger-text">not verified</span>' ?></dd></div>
            <div><dt>Gender</dt><dd><?= e($p['gender'] ?? '–') ?></dd></div>
            <div><dt>NID number</dt><dd><?= e($p['nid_number'] ?? '–') ?></dd></div>
            <div><dt>Reference</dt><dd><?= e(($p['reference_name'] ?? '–') . ' · ' . ($p['reference_phone'] ?? '')) ?></dd></div>
            <div><dt>Payout</dt><dd><?= e(ucfirst((string) $p['payout_method']) . ' ' . $p['payout_number']) ?></dd></div>
            <div><dt>Areas</dt><dd><?= e(implode(', ', array_map(static fn ($z) => $zones[$z] ?? '#' . $z, $p['zone_ids'])) ?: '–') ?></dd></div>
            <div><dt>Services</dt><dd><?= e(implode(', ', array_map(static fn ($c) => $categories[$c] ?? '#' . $c, $p['category_ids'])) ?: '–') ?></dd></div>
            <div><dt>Joined</dt><dd><?= e(format_date($p['joined_at'])) ?></dd></div>
        </dl>
        <div class="thumbs">
            <?php if ($p['nid_image']): ?><a class="thumb thumb--lg" href="<?= e(url('/admin/helpers/' . $id . '/doc/nid')) ?>" target="_blank" rel="noopener"><img src="<?= e(url('/admin/helpers/' . $id . '/doc/nid')) ?>" alt="NID"><span>National ID</span></a><?php endif; ?>
            <?php if ($p['selfie_image']): ?><a class="thumb thumb--lg" href="<?= e(url('/admin/helpers/' . $id . '/doc/selfie')) ?>" target="_blank" rel="noopener"><img src="<?= e(url('/admin/helpers/' . $id . '/doc/selfie')) ?>" alt="Selfie"><span>Selfie</span></a><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <h2>Decision</h2>
        <p class="small muted">Before approving: compare the selfie with the NID photo, check the NID number, and call the reference.</p>
        <form method="post" action="<?= e(url('/admin/helpers/' . $id . '/review')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field"><span>Note to the helper (required to reject or suspend)</span><input type="text" name="note" maxlength="255"></label>
            <div class="row-actions">
                <?php if (in_array($p['status'], ['pending', 'rejected'], true)): ?><button class="btn" name="action" value="approve" type="submit">Approve</button><?php endif; ?>
                <?php if ($p['status'] === 'pending'): ?><button class="btn btn--ghost" name="action" value="reject" type="submit">Reject</button><?php endif; ?>
                <?php if ($p['status'] === 'approved'): ?><button class="btn btn--danger" name="action" value="suspend" type="submit">Suspend</button><?php endif; ?>
                <?php if ($p['status'] === 'suspended'): ?><button class="btn" name="action" value="reactivate" type="submit">Reactivate</button><?php endif; ?>
            </div>
        </form>
        <h2>Record</h2>
        <dl class="details">
            <div><dt>Completed</dt><dd><?= (int) $p['completed_count'] ?></dd></div>
            <div><dt>Rating</dt><dd><?= (int) $p['rating_count'] > 0 ? e(number_format((float) $p['rating_avg'], 2)) . ' (' . (int) $p['rating_count'] . ')' : '–' ?></dd></div>
            <div><dt>Strikes (30 days)</dt><dd><?= (int) $strikes ?> / <?= (int) setting('strike_limit') ?></dd></div>
            <div><dt>Online</dt><dd><?= (int) $p['is_online'] === 1 ? 'yes' : 'no' ?><?= $p['last_seen_at'] ? ' · seen ' . e(format_datetime($p['last_seen_at'])) : '' ?></dd></div>
        </dl>
        <form method="post" action="<?= e(url('/admin/helpers/' . $id . '/float')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <label>Purchase limit (৳) <input type="text" name="float_limit" value="<?= e((string) (float) $p['float_limit']) ?>" size="8"></label>
            <button class="btn btn--sm" type="submit">Save</button>
        </form>
    </div>
</div>

<div class="card">
    <h2>Money · balance <span class="<?= $balance < 0 ? 'danger-text' : '' ?>"><?= e(money($balance)) ?></span></h2>
    <?php if ($payout): ?><p class="note">Payout requested: <?= e(money_db($payout['amount'])) ?> to <?= e($payout['method'] . ' ' . $payout['account_number']) ?>. Pay it on <a href="<?= e(url('/admin/money/helpers')) ?>">Helper money</a>.</p><?php endif; ?>
    <form method="post" action="<?= e(url('/admin/money/worker/' . $id . '/adjust')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <label>Adjustment (৳, negative = helper owes) <input type="text" name="amount" size="8" required></label>
        <label>Reason <input type="text" name="reason" maxlength="255" required></label>
        <button class="btn btn--ghost btn--sm" type="submit">Add</button>
    </form>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Date</th><th>Type</th><th>Task</th><th>Note</th><th class="num">Amount</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?><tr><td><?= e(format_datetime($r['created_at'])) ?></td><td><?= e($r['type']) ?></td><td><?= $r['task_number'] ? '<a href="' . e(url('/admin/tasks/' . $r['task_number'])) . '">' . e($r['task_number']) . '</a>' : '' ?></td><td><?= e($r['note']) ?></td><td class="num"><?= e(money_db($r['amount'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div>

<div class="card">
    <h2>Tasks</h2>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Task</th><th>Status</th><th>Date</th></tr></thead>
        <tbody><?php foreach (array_merge($active, $tasks) as $t): ?><tr><td><a href="<?= e(url('/admin/tasks/' . $t['task_number'])) ?>"><?= e($t['task_number']) ?></a> <?= e($t['title']) ?></td><td><?= e($t['status']) ?></td><td><?= e(format_datetime($t['completed_at'] ?? $t['created_at'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div>

<?php if ($log !== []): ?>
    <div class="card card--quiet">
        <h2>Staff actions</h2>
        <ul class="small"><?php foreach ($log as $l): ?><li><?= e(format_datetime($l['created_at'])) ?> · <?= e($l['admin_name'] ?? 'system') ?> · <?= e($l['action']) ?> <?= $l['details'] ? '<span class="muted">' . e($l['details']) . '</span>' : '' ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
