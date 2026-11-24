<h1 class="page__title">Helper money</h1>
<p class="small muted">Sunday payout run: pay every helper with a balance of at least <?= e(money($minPayout, false)) ?> to their wallet, then record the transaction ID. Record helpers' dues payments as settlements after checking the statement.</p>

<?php if ($requests !== []): ?>
    <div class="card">
        <h2>Payout requests</h2>
        <ul class="small"><?php foreach ($requests as $r): ?><li><?= e($r['name']) ?> · <?= e(money_db($r['amount'])) ?> · <?= e($r['method'] . ' ' . $r['account_number']) ?> · <?= e(format_datetime($r['created_at'])) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="table-wrap">
<table class="table">
    <thead><tr><th>Helper</th><th>Payout wallet</th><th class="num">Balance</th><th>Record</th></tr></thead>
    <tbody>
    <?php foreach ($balances as $b): $bal = (int) round((float) $b['balance'] * 100); ?>
        <tr>
            <td><a href="<?= e(url('/admin/helpers/' . $b['id'])) ?>"><?= e($b['name']) ?></a><br><small class="muted"><?= e($b['phone']) ?></small></td>
            <td><?= e(ucfirst((string) $b['payout_method']) . ' ' . $b['payout_number']) ?></td>
            <td class="num <?= $bal < 0 ? 'danger-text' : '' ?>"><strong><?= e(money($bal)) ?></strong><br><small class="muted"><?= $bal < 0 ? 'owes Helpigo' : 'Helpigo owes' ?></small></td>
            <td>
                <?php if ($bal > 0): ?>
                    <form method="post" action="<?= e(url('/admin/money/helpers/' . $b['id'] . '/payout')) ?>" class="inline-form"><?= csrf_field() ?>
                        <input type="text" name="amount" value="<?= e(App\Services\Money::toDecimal($bal)) ?>" size="8" aria-label="Amount">
                        <input type="text" name="trx_id" placeholder="TrxID" size="12" required>
                        <button class="btn btn--sm" type="submit">Paid</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/admin/money/helpers/' . $b['id'] . '/settlement')) ?>" class="inline-form"><?= csrf_field() ?>
                        <input type="text" name="amount" value="<?= e(App\Services\Money::toDecimal(-$bal)) ?>" size="8" aria-label="Amount">
                        <input type="text" name="trx_id" placeholder="TrxID" size="12" required>
                        <button class="btn btn--sm btn--ghost" type="submit">Received</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($balances === []): ?><tr><td colspan="4" class="muted">All balances are zero.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
