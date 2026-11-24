<?php
use App\Services\Money;
use App\Services\TaskStatus;

$user = current_user();
$status = $profile['status'] ?? 'incomplete';
$online = (int) ($profile['is_online'] ?? 0) === 1;
$submitted = in_array($status, ['pending', 'approved'], true);
$steps = [
    [t('Create account'), true],
    [t('Verify your mobile number'), $verified],
    [t('Add NID, selfie, reference, areas and services'), $submitted],
    [t('Approval by Helpigo team'), $status === 'approved'],
];
?>
<section class="container page page--narrow" <?= $status === 'approved' ? 'data-poll-url="' . e(url('/worker/feed')) . '" data-poll-version="' . e(md5(implode(',', array_column($feed, 'task_number')) . '|' . ($active ? App\Services\TaskFlowService::activeTaskId((int) $user['id']) : ''))) . '"' : '' ?>>
    <h1 class="page__title"><?= e(t('Hi :name', ['name' => strtok((string) $user['name'], ' ')])) ?></h1>

    <?php if ($status !== 'approved'): ?>
        <div class="card">
            <h2><?= e(t('Finish your helper profile')) ?></h2>
            <p class="muted"><?= e(t('Customers trust verified helpers. Complete these steps to start receiving tasks.')) ?></p>
            <ol class="checklist">
                <?php foreach ($steps as [$label, $done]): ?>
                    <li class="<?= $done ? 'is-done' : '' ?>"><?= e($label) ?></li>
                <?php endforeach; ?>
            </ol>
            <?php if ($status === 'pending'): ?>
                <p class="note"><?= e(t('Your profile is being reviewed. We usually reply within 1 working day.')) ?></p>
            <?php elseif ($status === 'rejected' || $status === 'suspended'): ?>
                <p class="note note--error"><?= e($status === 'rejected' ? t('Your profile needs changes') : t('Your account is paused')) ?>: <?= e($profile['review_note'] ?? '') ?></p>
            <?php endif; ?>
            <?php if (!$verified): ?>
                <a class="btn" href="<?= e(url('/verify-phone')) ?>"><?= e(t('Verify number')) ?></a>
            <?php elseif ($status !== 'suspended'): ?>
                <a class="btn" href="<?= e(url('/worker/profile')) ?>"><?= e($status === 'incomplete' ? t('Complete profile') : t('Edit profile')) ?></a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/worker/online')) ?>" class="card online-card online-card--<?= $online ? 'on' : 'off' ?>">
            <?= csrf_field() ?>
            <div>
                <strong><?= e($online ? t('You are online') : t('You are offline')) ?></strong>
                <p class="muted small"><?= e($online ? t('New tasks in your areas appear below.') : t('Go online to see and accept tasks.')) ?></p>
            </div>
            <input type="hidden" name="online" value="<?= $online ? '0' : '1' ?>">
            <button class="btn <?= $online ? 'btn--ghost' : '' ?>" type="submit"><?= e($online ? t('Go offline') : t('Go online')) ?></button>
        </form>

        <div class="stat-row">
            <div class="stat"><span class="stat__num"><?= (int) $profile['completed_count'] ?></span><span class="stat__label"><?= e(t('Tasks done')) ?></span></div>
            <div class="stat"><span class="stat__num"><?= (int) $profile['rating_count'] > 0 ? e(number_format((float) $profile['rating_avg'], 1)) : '–' ?></span><span class="stat__label"><?= e(t('Rating')) ?></span></div>
            <div class="stat"><span class="stat__num"><?= e(money_db($profile['float_limit'], false)) ?></span><span class="stat__label"><?= e(t('Purchase limit')) ?></span></div>
            <a class="stat stat--link" href="<?= e(url('/worker/earnings')) ?>"><span class="stat__num<?= $balance < 0 ? ' danger-text' : '' ?>"><?= e(money($balance, false)) ?></span><span class="stat__label"><?= e($balance < 0 ? t('You owe') : t('Balance')) ?></span></a>
        </div>

        <?php if ($active !== null): ?>
            <a class="card current-task" href="<?= e(url('/worker/tasks/' . $active['task_number'])) ?>">
                <span class="badge badge--<?= e($active['status']) ?>"><?= e(t(TaskStatus::label($active['status']))) ?></span>
                <strong><?= e($active['title']) ?></strong>
                <span class="small"><?= e(t('Open your current task')) ?> →</span>
            </a>
        <?php elseif ($block !== null && $online): ?>
            <p class="note note--error"><?= e(t($block)) ?></p>
        <?php endif; ?>

        <?php if ($online): ?>
            <h2 class="section-title"><?= e(t('Tasks near you')) ?> <span class="muted small">(<?= count($feed) ?>)</span></h2>
            <?php if ($feed === []): ?>
                <div class="empty"><?= partial('components/icon', ['name' => 'clock']) ?><p><?= e(t('No open tasks right now. This page updates by itself.')) ?></p></div>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($feed as $f): $net = Money::fromDecimal((string) $f['worker_fee']) - Money::fromDecimal((string) $f['commission']); ?>
                        <li>
                            <a class="list__row list__row--link" href="<?= e(url('/worker/tasks/' . $f['task_number'])) ?>">
                                <span class="list__icon"><?= partial('components/icon', ['name' => $f['category_icon']]) ?></span>
                                <span class="list__main">
                                    <strong><?= e($f['title']) ?></strong>
                                    <span class="muted small"><?= e($f['category_name']) ?> · <?= e($f['zone_name']) ?> · <?= $f['preferred_at'] ? e(format_datetime($f['preferred_at'])) : e(t('ASAP')) ?></span>
                                    <span class="small"><?= (int) $f['item_count'] > 0 ? e(t(':n items', ['n' => (int) $f['item_count']])) . ' · ' : '' ?><?= (float) $f['item_budget'] > 0 ? e(t('budget :b', ['b' => money_db($f['item_budget'], false)])) . ((int) $f['item_prepaid'] === 1 ? ' (' . e(t('prepaid')) . ')' : '') : '' ?></span>
                                </span>
                                <span class="earn"><?= $f['pricing_mode'] === 'offer' ? ($f['my_offer'] !== null ? e(t('Offer sent')) : e(t('Make offer'))) : e(money($net, false)) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
        <p class="small"><a href="<?= e(url('/worker/profile')) ?>"><?= e(t('Edit areas, services or payout wallet')) ?></a></p>
    <?php endif; ?>
</section>
