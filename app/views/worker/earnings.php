<?php
$labels = [
    'earning' => t('Helper fee'), 'commission' => t('Commission'), 'fees_collected_cash' => t('Fees collected in cash'),
    'advance_paid' => t('Item money sent to you'), 'item_reimbursement' => t('Items per receipt'), 'cancellation_fee' => t('Cancellation fee'),
    'payout' => t('Payout'), 'settlement' => t('You paid Helpigo'), 'adjustment' => t('Adjustment'),
];
$dues = (float) setting('max_worker_dues') * 100;
?>
<section class="container page page--narrow">
    <h1 class="page__title"><?= e(t('Earnings')) ?></h1>
    <div class="stat-row">
        <div class="stat"><span class="stat__num<?= $balance < 0 ? ' danger-text' : '' ?>"><?= e(money($balance)) ?></span><span class="stat__label"><?= e($balance < 0 ? t('You owe Helpigo') : t('Helpigo owes you')) ?></span></div>
        <div class="stat"><span class="stat__num"><?= e(money($week)) ?></span><span class="stat__label"><?= e(t('Earned this week')) ?></span></div>
    </div>

    <?php if ($balance < 0): ?>
        <div class="card">
            <h2><?= e(t('Pay your dues')) ?></h2>
            <p class="small"><?= e(t('For cash tasks you keep Helpigo\'s share. Send it with "Send Money" and tell support the transaction ID.')) ?></p>
            <?php foreach ($accounts as $method => $number): ?><p><strong><?= e($method === 'bkash' ? 'bKash' : 'Nagad') ?>: <?= e($number) ?></strong></p><?php endforeach; ?>
            <?php if (-$balance > $dues): ?><p class="note note--error"><?= e(t('You cannot take new tasks until you owe less than :cap.', ['cap' => money_db(setting('max_worker_dues'), false)])) ?></p><?php endif; ?>
        </div>
    <?php elseif ($request !== null): ?>
        <p class="note"><?= e(t('Payout of :amount requested to :wallet :number.', ['amount' => money_db($request['amount']), 'wallet' => ucfirst($request['method']), 'number' => $request['account_number']])) ?></p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/worker/earnings/payout')) ?>" class="card">
            <?= csrf_field() ?>
            <p class="small"><?= e(t('Helpigo pays positive balances every Sunday to :wallet :number.', ['wallet' => ucfirst((string) ($profile['payout_method'] ?? '')), 'number' => $profile['payout_number'] ?? '—'])) ?></p>
            <button class="btn btn--ghost" type="submit" <?= $balance < (float) setting('min_payout') * 100 ? 'disabled' : '' ?>><?= e(t('Request payout now')) ?></button>
        </form>
    <?php endif; ?>

    <h2 class="section-title"><?= e(t('History')) ?></h2>
    <?php if ($rows === []): ?>
        <div class="empty"><p><?= e(t('Nothing yet.')) ?></p></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($rows as $r): ?>
                <li class="list__row">
                    <span class="list__main"><strong><?= e($labels[$r['type']] ?? $r['type']) ?></strong><span class="muted small"><?= e(format_datetime($r['created_at'])) ?><?= $r['task_number'] ? ' · ' . e($r['task_number']) : '' ?><?= $r['note'] && in_array($r['type'], ['payout', 'settlement', 'adjustment', 'advance_paid'], true) ? ' · ' . e($r['note']) : '' ?></span></span>
                    <span class="amount <?= (float) $r['amount'] < 0 ? 'amount--neg' : 'amount--pos' ?>"><?= e(money_db($r['amount'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
