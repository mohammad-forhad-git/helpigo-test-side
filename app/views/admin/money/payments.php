<h1 class="page__title">Payments</h1>
<p class="small muted">Check each transaction ID in the <?= e(implode(' / ', array_map(static fn ($m, $n) => ($m === 'bkash' ? 'bKash' : 'Nagad') . ' ' . $n, array_keys($accounts), $accounts)) ?: 'merchant') ?> account statement before verifying. Verify only when the amount arrived.</p>
<nav class="segmented">
    <?php foreach (['pending' => 'To verify', 'verified' => 'Verified', 'rejected' => 'Rejected'] as $k => $label): ?>
        <a href="<?= e(url('/admin/payments', ['status' => $k])) ?>" class="<?= $status === $k ? 'is-active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Submitted</th><th>Customer</th><th>For</th><th>Wallet</th><th>From</th><th>TrxID</th><th class="num">Amount</th><th><?= $status === 'pending' ? 'Action' : 'Note' ?></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
        <tr>
            <td><?= e(format_datetime($p['created_at'])) ?></td>
            <td><?= e($p['payer_name']) ?><br><small class="muted"><?= e($p['payer_phone']) ?></small></td>
            <td><?= e(['item_advance' => 'Item advance', 'fees' => 'Task fees', 'due' => 'Dues'][$p['purpose']] ?? $p['purpose']) ?><?= $p['task_number'] ? '<br><a href="' . e(url('/admin/tasks/' . $p['task_number'])) . '"><small>' . e($p['task_number']) . '</small></a>' : '' ?></td>
            <td><?= e($p['method']) ?></td>
            <td><?= e($p['sender_number']) ?></td>
            <td><code><?= e($p['trx_id']) ?></code></td>
            <td class="num"><strong><?= e(money_db($p['amount'])) ?></strong></td>
            <td>
                <?php if ($status === 'pending'): ?>
                    <form method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/verify')) ?>" class="inline-form" data-confirm="Verify <?= e(money_db($p['amount'])) ?> TrxID <?= e($p['trx_id']) ?>?"><?= csrf_field() ?><button class="btn btn--sm" type="submit">Verify</button></form>
                    <details><summary class="small">Reject</summary>
                        <form method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/reject')) ?>" class="inline-form"><?= csrf_field() ?><input type="text" name="reason" placeholder="Reason" required><button class="btn btn--sm btn--danger" type="submit">Reject</button></form>
                    </details>
                <?php else: ?>
                    <small><?= e($p['note'] ?? '') ?> <?= e(format_datetime($p['verified_at'])) ?></small>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($payments === []): ?><tr><td colspan="8" class="muted">None.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
