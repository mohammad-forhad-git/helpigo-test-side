<?php
$user = current_user();
$labels = [
    'promo_credit' => t('Welcome credit'), 'referral_credit' => t('Referral credit'), 'credit_used' => t('Credit used'),
    'fees_due' => t('Fees to pay'), 'cancellation_due' => t('Cancellation fee'), 'payment' => t('Payment received'),
    'refund' => t('Refund'), 'refund_paid' => t('Refund sent to wallet'), 'adjustment' => t('Adjustment'),
];
$due = $balance < 0 ? -$balance : 0;
?>
<section class="container page page--narrow">
    <h1 class="page__title"><?= e(t('Account')) ?></h1>
    <div class="card">
        <p><strong><?= e($user['name']) ?></strong><br><span class="muted"><?= e($user['phone']) ?></span></p>
        <div class="stat-row">
            <div class="stat"><span class="stat__num"><?= e(money($credit, false)) ?></span><span class="stat__label"><?= e(t('Credit')) ?></span></div>
            <div class="stat"><span class="stat__num<?= $due > 0 ? ' danger-text' : '' ?>"><?= e(money($due, false)) ?></span><span class="stat__label"><?= e(t('To pay')) ?></span></div>
        </div>
        <?php if ($user['referral_code']): ?>
            <p class="small"><?= e(t('Invite friends with your code')) ?> <strong class="code-inline"><?= e($user['referral_code']) ?></strong>. <?= e(t('You both get :amount credit after their first task.', ['amount' => money_db(setting('referral_credit'), false)])) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($due > 0 && !$pendingDue): ?>
        <?= partial('components/wallet-pay', ['action' => '/app/account/pay', 'amount' => $due, 'purpose' => null, 'title' => t('Pay what you owe')]) ?>
    <?php elseif ($pendingDue): ?>
        <p class="note"><?= e(t('Your payment is being checked.')) ?></p>
    <?php endif; ?>

    <ul class="list">
        <li><a class="list__row list__row--link" href="<?= e(url('/app/addresses')) ?>"><span class="list__icon"><?= partial('components/icon', ['name' => 'home']) ?></span><span class="list__main"><strong><?= e(t('My addresses')) ?></strong><span class="muted small"><?= e(t(':n saved', ['n' => count($addresses)])) ?></span></span></a></li>
        <li><a class="list__row list__row--link" href="<?= e(url('/app/tasks', ['show' => 'past'])) ?>"><span class="list__icon"><?= partial('components/icon', ['name' => 'list']) ?></span><span class="list__main"><strong><?= e(t('Past tasks')) ?></strong></span></a></li>
        <li><a class="list__row list__row--link" href="<?= e(url('/notifications')) ?>"><span class="list__icon"><?= partial('components/icon', ['name' => 'chat']) ?></span><span class="list__main"><strong><?= e(t('Notifications')) ?></strong></span></a></li>
        <li><a class="list__row list__row--link" href="<?= e(url('/settings')) ?>"><span class="list__icon"><?= partial('components/icon', ['name' => 'user']) ?></span><span class="list__main"><strong><?= e(t('Settings')) ?></strong><span class="muted small"><?= e(t('Language and password')) ?></span></span></a></li>
        <li><a class="list__row list__row--link" href="<?= e(url('/terms')) ?>"><span class="list__icon"><?= partial('components/icon', ['name' => 'shield']) ?></span><span class="list__main"><strong><?= e(t('Policies and safety')) ?></strong></span></a></li>
    </ul>

    <?php if ($rows !== []): ?>
        <h2 class="section-title"><?= e(t('Money history')) ?></h2>
        <ul class="list">
            <?php foreach ($rows as $r): ?>
                <li class="list__row">
                    <span class="list__main"><strong><?= e($labels[$r['type']] ?? $r['type']) ?></strong><span class="muted small"><?= e(format_datetime($r['created_at'])) ?><?= $r['task_number'] ? ' · ' . e($r['task_number']) : '' ?></span></span>
                    <span class="amount <?= (float) $r['amount'] < 0 ? 'amount--neg' : 'amount--pos' ?>"><?= e(money_db($r['amount'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/logout')) ?>" class="page__foot">
        <?= csrf_field() ?>
        <button class="btn btn--ghost btn--block" type="submit"><?= partial('components/icon', ['name' => 'logout']) ?><?= e(t('Log out')) ?></button>
    </form>
</section>
