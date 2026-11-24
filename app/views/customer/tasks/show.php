<?php
use App\Services\ComplaintService;
use App\Services\Money;
use App\Services\TaskStatus;

$status = $task['status'];
$number = $task['task_number'];
$workerFee = Money::fromDecimal((string) $task['worker_fee']);
$platform = Money::fromDecimal((string) $task['platform_fee']);
$discount = Money::fromDecimal((string) $task['discount']);
$live = !in_array($status, TaskStatus::FINAL, true) || $status === TaskStatus::COMPLETED && $review === null;
$active = TaskStatus::isActive($status);
$kinds = ['list' => t('Shopping list'), 'prescription' => t('Prescription'), 'receipt' => t('Receipt'), 'pickup' => t('Pick-up photo'), 'delivery' => t('Delivery photo'), 'other' => t('Photo')];
$pendingApproval = null;
foreach ($approvals as $a) {
    if ($a['status'] === 'pending') {
        $pendingApproval = $a;
    }
}
$canReport = in_array($status, [TaskStatus::DELIVERING, TaskStatus::AWAITING], true)
    || ($status === TaskStatus::COMPLETED && strtotime((string) $task['completed_at']) > time() - (int) setting('dispute_window_hours') * 3600);
$base = '/app/tasks/' . $number;
$pendingPayment = static fn (string $purpose): bool => (bool) array_filter($payments, static fn ($p) => $p['purpose'] === $purpose && $p['status'] === 'pending');
?>
<section class="container page page--narrow"
    <?php if ($live): ?> data-poll-url="<?= e(url($base . '/status')) ?>" data-poll-version="<?= e($version) ?>"<?php endif; ?>>
    <a class="back-link" href="<?= e(url('/app/tasks')) ?>">← <?= e(t('My tasks')) ?></a>

    <div class="status-card status-card--<?= e($status) ?>">
        <span class="badge badge--<?= e($status) ?>"><?= e(t(TaskStatus::label($status))) ?></span>
        <h1 class="page__title"><?= e($task['title']) ?></h1>
        <p class="muted small"><?= e($number) ?> · <?= e($task['category_name']) ?> · <?= e($task['zone_name']) ?></p>
        <?php if ($status === TaskStatus::OPEN): ?>
            <p><?= e($task['pricing_mode'] === 'offer' ? t('Helpers nearby can send you offers. Choose the one you like below.') : t('We are showing your task to verified helpers nearby. You will see your helper here as soon as one accepts.')) ?></p>
            <p class="muted small"><?= e(t('If nobody accepts by :time, the task closes and you can post it again.', ['time' => format_datetime($task['expires_at'])])) ?></p>
        <?php elseif ($status === TaskStatus::AWAITING): ?>
            <p><?= e(t('Your helper says the task is delivered. Please confirm, or report a problem.')) ?></p>
            <form method="post" action="<?= e(url($base . '/confirm')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= e(t('Yes, I received everything')) ?></button></form>
        <?php elseif ($status === TaskStatus::DISPUTED): ?>
            <p><?= e(t('Helpigo support is reviewing your report. We decide within 72 hours.')) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($needsPrepay): ?>
        <?php if ($pendingPayment('item_advance')): ?>
            <p class="note"><?= e(t('Your advance payment is being checked. Your task is shown to all helpers once it is confirmed.')) ?></p>
        <?php else: ?>
            <p class="note"><?= e(t('Your item budget is above what new helpers can spend. Pay the budget in advance so any helper can take the task, or wait for an experienced helper.')) ?></p>
            <?= partial('components/wallet-pay', ['action' => $base . '/pay', 'amount' => Money::fromDecimal((string) $task['item_budget']), 'purpose' => 'item_advance', 'title' => t('Pay item budget in advance')]) ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($offers !== []): ?>
        <div class="card">
            <h2><?= e(t('Offers')) ?></h2>
            <ul class="list list--flat">
                <?php foreach ($offers as $o): ?>
                    <li class="list__row">
                        <span class="list__main">
                            <strong><?= e($o['worker_name']) ?> · <?= e(money_db($o['amount'], false)) ?></strong>
                            <span class="muted small"><?= (int) $o['rating_count'] > 0 ? '★ ' . e(number_format((float) $o['rating_avg'], 1)) . ' · ' : '' ?><?= e(t(':n tasks done', ['n' => (int) $o['completed_count']])) ?></span>
                            <?php if ($o['note']): ?><span class="small"><?= e($o['note']) ?></span><?php endif; ?>
                        </span>
                        <form method="post" action="<?= e(url($base . '/offer')) ?>"><?= csrf_field() ?><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>"><button class="btn btn--sm" type="submit"><?= e(t('Accept')) ?></button></form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($worker !== null): ?>
        <div class="card helper-card">
            <img src="<?= e(url('/files/helper/' . $worker['id'])) ?>" alt="" width="64" height="64" class="avatar">
            <div class="list__main">
                <strong><?= e($worker['name']) ?></strong>
                <span class="muted small"><?= (int) $worker['rating_count'] > 0 ? '★ ' . e(number_format((float) $worker['rating_avg'], 1)) . ' · ' : '' ?><?= e(t(':n tasks done', ['n' => (int) $worker['completed_count']])) ?> · <?= e(t('ID verified')) ?></span>
            </div>
            <?php if ($active): ?><a class="btn btn--ghost btn--sm" href="tel:<?= e($worker['phone']) ?>"><?= e(t('Call')) ?></a><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($pendingApproval !== null): ?>
        <div class="card approval-card">
            <h2><?= e(t('Your helper asks')) ?></h2>
            <p><?= e($pendingApproval['description']) ?></p>
            <?php if ($pendingApproval['new_budget'] !== null): ?>
                <p><strong><?= e(t('New item budget')) ?>: <?= e(money_db($pendingApproval['new_budget'])) ?></strong> <span class="muted small">(<?= e(t('was :old', ['old' => money_db($task['item_budget'])])) ?>)</span></p>
            <?php endif; ?>
            <form method="post" action="<?= e(url($base . '/approval')) ?>" class="row-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="approval_id" value="<?= (int) $pendingApproval['id'] ?>">
                <button class="btn" type="submit" name="decision" value="approve"><?= e(t('Approve')) ?></button>
                <button class="btn btn--ghost" type="submit" name="decision" value="decline"><?= e(t('Decline')) ?></button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($active): ?>
        <div class="card code-card">
            <?= partial('components/icon', ['name' => 'key']) ?>
            <div>
                <p class="muted small"><?= e(t('Your delivery code')) ?></p>
                <p class="code-card__code"><?= e($task['delivery_code']) ?></p>
                <p class="small"><?= e(t('Give it to the helper only after you have checked your items.')) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($feesDue > 0 && in_array($status, [TaskStatus::ASSIGNED, TaskStatus::IN_PROGRESS, TaskStatus::DELIVERING, TaskStatus::AWAITING, TaskStatus::COMPLETED], true)): ?>
        <?= partial('components/wallet-pay', ['action' => $base . '/pay', 'amount' => $feesDue, 'purpose' => 'fees', 'title' => t('Pay fees')]) ?>
    <?php elseif ($pendingPayment('fees')): ?>
        <p class="note"><?= e(t('Your payment is being checked.')) ?></p>
    <?php endif; ?>

    <?php if ($task['worker_id'] !== null && ($active || $status === TaskStatus::DISPUTED)): ?>
        <?= partial('components/chat', ['number' => $number, 'canWrite' => true]) ?>
    <?php endif; ?>

    <?php if ($status === TaskStatus::COMPLETED && $review === null && strtotime((string) $task['completed_at']) > time() - 7 * 86400): ?>
        <form method="post" action="<?= e(url($base . '/rate')) ?>" class="card form">
            <?= csrf_field() ?>
            <h2><?= e(t('How was your helper?')) ?></h2>
            <div class="stars" role="radiogroup" aria-label="<?= e(t('Rating')) ?>">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" name="rating" value="<?= $i ?>" id="star<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>><label for="star<?= $i ?>" title="<?= $i ?>">★</label>
                <?php endfor; ?>
            </div>
            <label class="field"><span><?= e(t('Comment (optional)')) ?></span><input type="text" name="comment" maxlength="500"></label>
            <button class="btn" type="submit"><?= e(t('Send rating')) ?></button>
        </form>
    <?php elseif ($review !== null): ?>
        <p class="note"><?= e(t('You rated this task')) ?> <?= str_repeat('★', (int) $review['rating']) ?></p>
    <?php endif; ?>

    <div class="card">
        <h2><?= e(t('Details')) ?></h2>
        <dl class="details">
            <?php if ($task['pickup_location']): ?>
                <div><dt><?= e((int) $task['involves_purchase'] === 1 ? t('Shop') : t('Pick-up')) ?></dt><dd><?= e($task['pickup_location']) ?></dd></div>
            <?php endif; ?>
            <?php if ($task['dropoff_address']): ?>
                <div><dt><?= e(t('Deliver to')) ?></dt><dd><?= e($task['dropoff_address']) ?><?= $task['dropoff_landmark'] ? '<br><small class="muted">' . e($task['dropoff_landmark']) . '</small>' : '' ?></dd></div>
            <?php endif; ?>
            <?php if ($task['recipient_name']): ?>
                <div><dt><?= e(t('Receiver')) ?></dt><dd><?= e($task['recipient_name']) ?> · <?= e($task['recipient_phone']) ?></dd></div>
            <?php endif; ?>
            <div><dt><?= e(t('When')) ?></dt><dd><?= $task['preferred_at'] ? e(format_datetime($task['preferred_at'])) : e(t('As soon as possible')) ?></dd></div>
            <div><dt><?= e(t('Payment')) ?></dt><dd><?= e(['cash' => t('Cash'), 'bkash' => 'bKash', 'nagad' => 'Nagad'][$task['payment_method']] ?? '') ?><?= (int) $task['item_prepaid'] === 1 ? ' · ' . e(t('items prepaid')) : '' ?></dd></div>
            <?php if ((int) $task['prefer_female_worker'] === 1): ?>
                <div><dt><?= e(t('Helper')) ?></dt><dd><?= e(t('Female helper preferred')) ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if ($task['description']): ?><p class="note"><?= nl2br_e($task['description']) ?></p><?php endif; ?>
    </div>

    <?php if ($task['items'] !== [] || $task['attachments'] !== []): ?>
        <div class="card">
            <h2><?= e(t('Items')) ?></h2>
            <?php if ($task['items'] !== []): ?>
                <ul class="item-list">
                    <?php foreach ($task['items'] as $item): ?>
                        <li><span><?= e($item['name']) ?><?= $item['note'] ? '<br><small class="muted">' . e($item['note']) . '</small>' : '' ?></span>
                            <span class="muted"><?= e($item['quantity'] ?? '') ?><?= $item['status'] !== 'pending' ? ' · <span class="badge badge--item-' . e($item['status']) . '">' . e(t(ucfirst($item['status']))) . '</span>' : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($task['attachments'] !== []): ?>
                <div class="thumbs">
                    <?php foreach ($task['attachments'] as $file): ?>
                        <a href="<?= e(url('/files/task/' . $file['id'])) ?>" target="_blank" rel="noopener" class="thumb">
                            <img src="<?= e(url('/files/task/' . $file['id'])) ?>" alt="<?= e($kinds[$file['kind']] ?? '') ?>" loading="lazy" width="96" height="96">
                            <span><?= e($kinds[$file['kind']] ?? '') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card summary">
        <h2><?= e(t('Price')) ?></h2>
        <dl class="summary__rows">
            <div><dt><?= e(t('Helper fee')) ?></dt><dd><?= e(money($workerFee)) ?></dd></div>
            <div><dt><?= e(t('Platform fee')) ?></dt><dd><?= e(money($platform)) ?></dd></div>
            <?php if ($discount > 0): ?><div><dt><?= e(t('Discount')) ?></dt><dd>−<?= e(money($discount)) ?></dd></div><?php endif; ?>
            <div class="summary__total"><dt><?= e(t('Total fees')) ?></dt><dd><?= e(money($workerFee + $platform - $discount)) ?></dd></div>
            <?php if ($task['item_cost'] !== null): ?>
                <div><dt><?= e(t('Items (receipt)')) ?></dt><dd><?= e(money_db($task['item_cost'])) ?></dd></div>
                <div class="summary__total"><dt><?= e(t('Total')) ?></dt><dd><?= e(money($workerFee + $platform - $discount + Money::fromDecimal((string) $task['item_cost']))) ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if ((int) $task['involves_purchase'] === 1 && $task['item_cost'] === null): ?>
            <p class="muted small"><?= e(t('Items: up to :budget at the receipt price.', ['budget' => money_db($task['item_budget'], false)])) ?></p>
        <?php endif; ?>
        <?php if ((float) $task['cancellation_fee'] > 0): ?><p class="small"><?= e(t('Cancellation fee')) ?>: <?= e(money_db($task['cancellation_fee'])) ?></p><?php endif; ?>
    </div>

    <div class="card card--quiet">
        <h2><?= e(t('Timeline')) ?></h2>
        <ol class="timeline">
            <?php foreach (array_reverse($task['events']) as $event): ?>
                <li><strong><?= e(t(TaskStatus::label($event['to_status']))) ?></strong> <span class="muted small"><?= e(format_datetime($event['created_at'])) ?></span><?= $event['note'] ? '<br><small class="muted">' . e($event['note']) . '</small>' : '' ?></li>
            <?php endforeach; ?>
        </ol>
    </div>

    <?php if ($canReport): ?>
        <details class="card card--quiet">
            <summary><?= e(t('Report a problem')) ?></summary>
            <form method="post" action="<?= e(url($base . '/report')) ?>" class="form">
                <?= csrf_field() ?>
                <label class="field"><span><?= e(t('What went wrong?')) ?></span>
                    <select name="type" required>
                        <?php foreach (ComplaintService::TYPES as $key => $label): ?><option value="<?= e($key) ?>"><?= e(t($label)) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span><?= e(t('Describe it')) ?></span><textarea name="description" rows="3" maxlength="1000" required minlength="10"></textarea></label>
                <button class="btn btn--danger" type="submit"><?= e(t('Send report')) ?></button>
            </form>
        </details>
    <?php endif; ?>

    <?php if ($active): ?><?= partial('components/sos', ['number' => $number]) ?><?php endif; ?>

    <?php if (in_array($status, [TaskStatus::OPEN, TaskStatus::ASSIGNED, TaskStatus::IN_PROGRESS], true) && $task['item_cost'] === null): ?>
        <details class="card card--quiet">
            <summary class="danger-text"><?= e(t('Cancel this task')) ?></summary>
            <form method="post" action="<?= e(url($base . '/cancel')) ?>" class="form">
                <?= csrf_field() ?>
                <label class="field"><span><?= e(t('Reason (optional)')) ?></span><input type="text" name="reason" maxlength="255"></label>
                <p class="muted small"><?= $status === TaskStatus::OPEN ? e(t('Cancelling is free until a helper accepts.')) : e(t('A cancellation fee of :fee goes to your helper.', ['fee' => money_db(setting('cancel_fee_assigned'), false)])) ?></p>
                <button class="btn btn--danger" type="submit"><?= e(t('Cancel task')) ?></button>
            </form>
        </details>
    <?php endif; ?>
</section>
