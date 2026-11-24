<?php
use App\Services\Money;
use App\Services\TaskStatus;

$number = $task['task_number'];
$base = '/admin/tasks/' . $number;
$status = $task['status'];
?>
<a class="back-link" href="<?= e(url('/admin/tasks')) ?>">← Tasks</a>
<h1 class="page__title"><?= e($number) ?> · <?= e($task['title']) ?> <span class="badge badge--<?= e($status) ?>"><?= e(TaskStatus::label($status)) ?></span></h1>
<?php if ((int) $task['needs_review'] === 1 && $status === TaskStatus::OPEN): ?>
    <form method="post" action="<?= e(url($base . '/clear-review')) ?>" class="note note--error inline-form">
        <?= csrf_field() ?>
        <span>Flagged for review: hidden from helpers. Check the details; release it or cancel it.</span>
        <button class="btn btn--sm" type="submit">Release to helpers</button>
    </form>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h2>Details</h2>
        <dl class="details">
            <div><dt>Service</dt><dd><?= e($task['category_name']) ?> · <?= e($task['zone_name']) ?></dd></div>
            <div><dt>Customer</dt><dd><a href="<?= e(url('/admin/customers/' . $task['customer_id'])) ?>"><?= e($task['customer_name']) ?></a> · <?= e($task['customer_phone']) ?></dd></div>
            <div><dt>Helper</dt><dd><?= $task['worker_id'] ? '<a href="' . e(url('/admin/helpers/' . $task['worker_id'])) . '">' . e($task['worker_name']) . '</a> · ' . e($task['worker_phone']) : '–' ?></dd></div>
            <div><dt>Shop / pick-up</dt><dd><?= e($task['pickup_location'] ?? '–') ?></dd></div>
            <div><dt>Drop-off</dt><dd><?= e($task['dropoff_address'] ?? '–') ?><?= $task['dropoff_landmark'] ? ' (' . e($task['dropoff_landmark']) . ')' : '' ?></dd></div>
            <?php if ($task['recipient_name']): ?><div><dt>Receiver</dt><dd><?= e($task['recipient_name'] . ' · ' . $task['recipient_phone']) ?></dd></div><?php endif; ?>
            <div><dt>When</dt><dd><?= $task['preferred_at'] ? e(format_datetime($task['preferred_at'])) : 'ASAP' ?> · posted <?= e(format_datetime($task['created_at'])) ?></dd></div>
            <div><dt>Payment</dt><dd><?= e($task['payment_method']) ?><?= (int) $task['item_prepaid'] === 1 ? ' · items prepaid (' . e(money($advance)) . ')' : '' ?></dd></div>
            <div><dt>Fees</dt><dd>helper <?= e(money_db($task['worker_fee'])) ?> · platform <?= e(money_db($task['platform_fee'])) ?> · commission <?= e(money_db($task['commission'])) ?> · discount <?= e(money_db($task['discount'])) ?></dd></div>
            <div><dt>Items</dt><dd>budget <?= e(money_db($task['item_budget'])) ?><?= $task['item_cost'] !== null ? ' · receipt ' . e(money_db($task['item_cost'])) : '' ?></dd></div>
            <?php if ((int) $task['prefer_female_worker'] === 1): ?><div><dt>Preference</dt><dd>Female helper</dd></div><?php endif; ?>
            <?php if ($task['cancel_reason']): ?><div><dt>Cancel reason</dt><dd><?= e($task['cancel_reason']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($task['description']): ?><p class="note"><?= nl2br_e($task['description']) ?></p><?php endif; ?>
        <?php if ($items !== []): ?>
            <ul class="item-list"><?php foreach ($items as $i): ?><li><span><?= e($i['name']) ?><?= $i['note'] ? ' <small class="muted">' . e($i['note']) . '</small>' : '' ?></span><span><?= e($i['quantity'] ?? '') ?> · <?= e($i['status']) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <div class="thumbs"><?php foreach ($attachments as $f): ?><a class="thumb" href="<?= e(url('/files/task/' . $f['id'])) ?>" target="_blank" rel="noopener"><img src="<?= e(url('/files/task/' . $f['id'])) ?>" alt="" width="96" height="96" loading="lazy"><span><?= e($f['kind']) ?></span></a><?php endforeach; ?></div>
    </div>

    <div>
        <?php if ($status === TaskStatus::DISPUTED): ?>
            <form method="post" action="<?= e(url($base . '/resolve')) ?>" class="card form">
                <?= csrf_field() ?>
                <h2>Decide the dispute</h2>
                <p class="small muted">Check the receipt, photos, chat and timeline. "Completed" pays the helper as normal; "Cancelled" pays nothing. A refund becomes customer credit.</p>
                <div class="segmented segmented--form">
                    <label><input type="radio" name="outcome" value="completed" checked><span>Completed</span></label>
                    <label><input type="radio" name="outcome" value="cancelled"><span>Cancelled</span></label>
                </div>
                <label class="field"><span>Refund to customer (৳, optional)</span><input type="text" name="refund" inputmode="decimal"></label>
                <label class="field"><span>Decision (sent to both sides)</span><textarea name="resolution" rows="3" required></textarea></label>
                <button class="btn" type="submit">Save decision</button>
            </form>
        <?php endif; ?>

        <?php if ((int) $task['item_prepaid'] === 1 && $task['worker_id'] !== null && in_array($status, [TaskStatus::ASSIGNED, TaskStatus::IN_PROGRESS], true) && !$advanceSent): ?>
            <form method="post" action="<?= e(url($base . '/advance')) ?>" class="card form">
                <?= csrf_field() ?>
                <h2>Send item money to the helper</h2>
                <p>Send <strong><?= e(money($advance)) ?></strong> to <?= e($task['worker_name']) ?>'s payout wallet, then record the transaction ID.</p>
                <label class="field"><span>Transaction ID</span><input type="text" name="trx_id" required></label>
                <button class="btn" type="submit">Record advance</button>
            </form>
        <?php endif; ?>

        <div class="card">
            <h2>Timeline</h2>
            <ol class="timeline">
                <?php foreach ($events as $ev): ?>
                    <li><strong><?= e(TaskStatus::label($ev['to_status'])) ?></strong> <span class="muted small"><?= e(format_datetime($ev['created_at'])) ?> · <?= e($ev['actor_name'] ? $ev['actor_name'] . ' (' . $ev['actor_role'] . ')' : 'system') ?></span><?= $ev['note'] ? '<br><small>' . e($ev['note']) . '</small>' : '' ?></li>
                <?php endforeach; ?>
            </ol>
        </div>

        <?php if (!in_array($status, TaskStatus::FINAL, true)): ?>
            <details class="card card--quiet">
                <summary class="danger-text">Cancel this task</summary>
                <form method="post" action="<?= e(url($base . '/cancel')) ?>" class="form">
                    <?= csrf_field() ?>
                    <label class="field"><span>Reason (sent to both sides)</span><input type="text" name="reason" required maxlength="255"></label>
                    <p class="small muted">No fees are charged. Credit used is returned and verified advances are refunded as credit. If item money was sent to the helper, it stays on their balance to return.</p>
                    <button class="btn btn--danger" type="submit">Cancel task</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h2>Money on this task</h2>
        <h3>Payments</h3>
        <?php if ($payments === []): ?><p class="muted small">None.</p><?php else: ?>
            <ul class="small"><?php foreach ($payments as $p): ?><li><?= e($p['purpose']) ?> · <?= e($p['method']) ?> <?= e($p['trx_id']) ?> · <?= e(money_db($p['amount'])) ?> · <strong><?= e($p['status']) ?></strong></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h3>Helper ledger</h3>
        <?php if ($workerRows === []): ?><p class="muted small">None.</p><?php else: ?>
            <ul class="small"><?php foreach ($workerRows as $r): ?><li><?= e($r['type']) ?> <?= e(money_db($r['amount'])) ?> <span class="muted"><?= e($r['note']) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h3>Customer ledger</h3>
        <?php if ($customerRows === []): ?><p class="muted small">None.</p><?php else: ?>
            <ul class="small"><?php foreach ($customerRows as $r): ?><li><?= e($r['type']) ?> <?= e(money_db($r['amount'])) ?> <span class="muted"><?= e($r['note']) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Chat (<?= count($messages) ?>)</h2>
        <?php if ($messages === []): ?><p class="muted small">No messages.</p><?php else: ?>
            <ul class="chat-log-admin small"><?php foreach ($messages as $m): ?><li><strong><?= e($m['sender_name']) ?></strong> <span class="muted"><?= e(format_datetime($m['created_at'])) ?></span><br><?= e($m['body']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($offers !== []): ?><h3>Offers</h3><ul class="small"><?php foreach ($offers as $o): ?><li><?= e($o['worker_name']) ?> · <?= e(money_db($o['amount'])) ?> · <?= e($o['status']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($approvals !== []): ?><h3>Approvals</h3><ul class="small"><?php foreach ($approvals as $a): ?><li><?= e($a['type']) ?>: <?= e($a['description']) ?><?= $a['new_budget'] ? ' → ' . e(money_db($a['new_budget'])) : '' ?> · <strong><?= e($a['status']) ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($complaints !== []): ?><h3>Complaints</h3><ul class="small"><?php foreach ($complaints as $c): ?><li><?= $c['priority'] ? '<strong class="danger-text">SOS</strong> ' : '' ?><?= e($c['type']) ?>: <?= e($c['description']) ?> · <?= e($c['status']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($reviews !== []): ?><h3>Ratings</h3><ul class="small"><?php foreach ($reviews as $rv): ?><li><?= e($rv['reviewer_name']) ?>: <?= str_repeat('★', (int) $rv['rating']) ?> <?= e($rv['comment']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
</div>
