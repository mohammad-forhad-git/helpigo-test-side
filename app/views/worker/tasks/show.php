<?php
use App\Services\Money;
use App\Services\TaskStatus;

$status = $task['status'];
$number = $task['task_number'];
$base = '/worker/tasks/' . $number;
$fee = Money::fromDecimal((string) $task['worker_fee']);
$commission = Money::fromDecimal((string) $task['commission']);
$customerFees = $fee + Money::fromDecimal((string) $task['platform_fee']) - Money::fromDecimal((string) $task['discount']);
$purchase = (int) $task['involves_purchase'] === 1;
$pending = null;
foreach ($task['approvals'] as $a) {
    if ($a['status'] === 'pending') {
        $pending = $a;
    }
}
$hasReceipt = (bool) array_filter($task['attachments'], static fn ($f) => $f['kind'] === 'receipt');
$kinds = ['list' => t('Shopping list'), 'prescription' => t('Prescription'), 'receipt' => t('Receipt'), 'pickup' => t('Pick-up photo'), 'delivery' => t('Delivery photo'), 'other' => t('Photo')];
$live = !in_array($status, TaskStatus::FINAL, true);
$pendingCount = $pending !== null ? 1 : 0;
?>
<section class="container page page--narrow"<?php if ($live): ?> data-poll-url="<?= e(url($base . '/status')) ?>" data-poll-version="<?= e($status . '.' . count($task['approvals']) . '.' . $pendingCount . '.' . ($task['advance_sent'] ? 1 : 0) . '.' . ($task['is_mine'] ? 1 : 0)) ?>"<?php endif; ?>>
    <a class="back-link" href="<?= e(url('/worker')) ?>">← <?= e(t('Home')) ?></a>

    <div class="status-card status-card--<?= e($status) ?>">
        <span class="badge badge--<?= e($status) ?>"><?= e(t(TaskStatus::label($status))) ?></span>
        <h1 class="page__title"><?= e($task['title']) ?></h1>
        <p class="muted small"><?= e($number) ?> · <?= e($task['category_name']) ?> · <?= e($task['zone_name']) ?> · <?= $task['preferred_at'] ? e(format_datetime($task['preferred_at'])) : e(t('ASAP')) ?></p>
    </div>

    <div class="card summary">
        <h2><?= e(t('Your earnings')) ?></h2>
        <dl class="summary__rows">
            <div><dt><?= e(t('Helper fee')) ?></dt><dd><?= e(money($fee)) ?></dd></div>
            <div><dt><?= e(t('Helpigo commission')) ?></dt><dd>−<?= e(money($commission)) ?></dd></div>
            <div class="summary__total"><dt><?= e(t('You earn')) ?></dt><dd><?= e(money($fee - $commission)) ?></dd></div>
        </dl>
        <p class="small">
            <?php if ($task['payment_method'] === 'cash'): ?>
                <?= e(t('Cash task: collect :fees in fees', ['fees' => money($customerFees)])) ?><?= $purchase ? ' ' . e(t('plus the receipt amount for items')) : '' ?>.
            <?php else: ?>
                <?= e(t('The customer pays the fees to Helpigo by wallet.')) ?><?= $purchase && (int) $task['item_prepaid'] !== 1 ? ' ' . e(t('Collect the receipt amount for items at hand-over.')) : '' ?>
            <?php endif; ?>
            <?php if ((int) $task['item_prepaid'] === 1): ?><br><?= e(t('Items are prepaid: Helpigo sends you the item money before you start.')) ?><?php endif; ?>
        </p>
    </div>

    <?php if (!$task['is_mine'] && $status === TaskStatus::OPEN): ?>
        <?php if ($block !== null): ?>
            <p class="note note--error"><?= e(t($block)) ?></p>
        <?php elseif ($task['pricing_mode'] === 'offer'): ?>
            <form method="post" action="<?= e(url($base . '/offer')) ?>" class="card form">
                <?= csrf_field() ?>
                <h2><?= e($task['my_offer'] ? t('Update your offer') : t('Send an offer')) ?></h2>
                <p class="small"><?= e(t('The customer suggested :fee.', ['fee' => money($fee, false)])) ?></p>
                <label class="field"><span><?= e(t('Your fee (৳)')) ?></span><input type="text" name="amount" inputmode="decimal" required value="<?= e($task['my_offer'] ? (string) (float) $task['my_offer']['amount'] : (string) ($fee / 100)) ?>"></label>
                <label class="field"><span><?= e(t('Note (optional)')) ?></span><input type="text" name="note" maxlength="255" value="<?= e($task['my_offer']['note'] ?? '') ?>"></label>
                <button class="btn btn--block" type="submit"><?= e(t('Send offer')) ?></button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= e(url($base . '/accept')) ?>" class="card">
                <?= csrf_field() ?>
                <p class="small"><?= e(t('You will see the drop-off address after accepting. Only accept if you can start soon.')) ?></p>
                <button class="btn btn--block btn--lg" type="submit"><?= e(t('Accept task')) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($task['is_active']): ?>
        <div class="card">
            <h2><?= e(t('Customer')) ?></h2>
            <dl class="details">
                <div><dt><?= e(t('Name')) ?></dt><dd><?= e($task['recipient_name'] ?: $task['customer_name']) ?></dd></div>
                <div><dt><?= e(t('Phone')) ?></dt><dd><a href="tel:<?= e($task['recipient_phone'] ?: $task['customer_phone']) ?>"><?= e($task['recipient_phone'] ?: $task['customer_phone']) ?></a></dd></div>
                <div><dt><?= e(t('Deliver to')) ?></dt><dd><?= e($task['dropoff_address']) ?><?= $task['dropoff_landmark'] ? '<br><small class="muted">' . e($task['dropoff_landmark']) . '</small>' : '' ?></dd></div>
            </dl>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2><?= e(t('Task')) ?></h2>
        <dl class="details">
            <?php if ($task['pickup_location']): ?><div><dt><?= e($purchase ? t('Shop') : t('Pick-up')) ?></dt><dd><?= e($task['pickup_location']) ?></dd></div><?php endif; ?>
            <?php if ($purchase): ?><div><dt><?= e(t('Item budget')) ?></dt><dd><?= e(money_db($task['item_budget'])) ?></dd></div><?php endif; ?>
            <?php if ($task['item_cost'] !== null): ?><div><dt><?= e(t('Receipt total')) ?></dt><dd><?= e(money_db($task['item_cost'])) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($task['description']): ?><p class="note"><?= nl2br_e($task['description']) ?></p><?php endif; ?>
        <?php if ($task['attachments'] !== [] && $task['is_mine']): ?>
            <div class="thumbs">
                <?php foreach ($task['attachments'] as $file): ?>
                    <a href="<?= e(url('/files/task/' . $file['id'])) ?>" target="_blank" rel="noopener" class="thumb"><img src="<?= e(url('/files/task/' . $file['id'])) ?>" alt="" loading="lazy" width="96" height="96"><span><?= e($kinds[$file['kind']] ?? '') ?></span></a>
                <?php endforeach; ?>
            </div>
        <?php elseif ($task['attachments'] !== []): ?>
            <p class="muted small"><?= e(t(':n photos (list or prescription) open after you accept.', ['n' => count($task['attachments'])])) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($task['items'] !== []): ?>
        <?php if ($status === TaskStatus::IN_PROGRESS): ?>
            <form method="post" action="<?= e(url($base . '/receipt')) ?>" enctype="multipart/form-data" class="card form">
                <?= csrf_field() ?>
                <h2><?= e(t('Shopping list')) ?></h2>
                <ul class="item-list">
                    <?php foreach ($task['items'] as $item): ?>
                        <li>
                            <span><?= e($item['name']) ?> <span class="muted"><?= e($item['quantity'] ?? '') ?></span><?= $item['note'] ? '<br><small class="muted">' . e($item['note']) . '</small>' : '' ?></span>
                            <select name="item_status[<?= (int) $item['id'] ?>]" aria-label="<?= e(t('Item status')) ?>">
                                <?php foreach (['pending' => t('To buy'), 'bought' => t('Bought'), 'substituted' => t('Substituted'), 'unavailable' => t('Unavailable')] as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= $item['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <label class="field"><span><?= e(t('Receipt total (৳)')) ?></span><input type="text" name="item_cost" inputmode="decimal" value="<?= e($task['item_cost'] !== null ? (string) (float) $task['item_cost'] : '') ?>" required></label>
                <label class="field"><span><?= e(t('Receipt photo')) ?><?= $hasReceipt ? ' <small class="muted">— ' . e(t('saved')) . '</small>' : '' ?></span><input type="file" name="receipt" accept="image/jpeg,image/png,image/webp" capture="environment"></label>
                <button class="btn" type="submit"><?= e(t('Save receipt')) ?></button>
            </form>
        <?php else: ?>
            <div class="card">
                <h2><?= e(t('Shopping list')) ?></h2>
                <ul class="item-list">
                    <?php foreach ($task['items'] as $item): ?><li><span><?= e($item['name']) ?><?= $item['note'] ? '<br><small class="muted">' . e($item['note']) . '</small>' : '' ?></span><span class="muted"><?= e($item['quantity'] ?? '') ?></span></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php elseif ($status === TaskStatus::IN_PROGRESS && $purchase): ?>
        <form method="post" action="<?= e(url($base . '/receipt')) ?>" enctype="multipart/form-data" class="card form">
            <?= csrf_field() ?>
            <h2><?= e(t('Receipt')) ?></h2>
            <label class="field"><span><?= e(t('Receipt total (৳)')) ?></span><input type="text" name="item_cost" inputmode="decimal" required></label>
            <label class="field"><span><?= e(t('Receipt photo')) ?></span><input type="file" name="receipt" accept="image/jpeg,image/png,image/webp" capture="environment"></label>
            <button class="btn" type="submit"><?= e(t('Save receipt')) ?></button>
        </form>
    <?php endif; ?>

    <?php if ($pending !== null): ?>
        <p class="note"><?= e(t('Waiting for the customer to answer: :q', ['q' => $pending['description']])) ?></p>
    <?php endif; ?>

    <?php if ($task['is_mine']): ?>
        <div class="card actions">
            <?php if ($status === TaskStatus::ASSIGNED): ?>
                <?php if ((int) $task['item_prepaid'] === 1 && !$task['advance_sent']): ?>
                    <p class="note"><?= e(t('Helpigo will send the item money to your wallet. You can start after that.')) ?></p>
                <?php endif; ?>
                <form method="post" action="<?= e(url($base . '/start')) ?>"><?= csrf_field() ?><button class="btn btn--block btn--lg" type="submit"><?= e(t('Start task')) ?></button></form>
            <?php elseif ($status === TaskStatus::IN_PROGRESS): ?>
                <?php if ($purchase && $pending === null): ?>
                    <details>
                        <summary><?= e(t('Price higher or item missing? Ask the customer')) ?></summary>
                        <form method="post" action="<?= e(url($base . '/approval')) ?>" class="form">
                            <?= csrf_field() ?>
                            <label class="field"><span><?= e(t('What do you need?')) ?></span>
                                <select name="type"><option value="over_budget"><?= e(t('More budget')) ?></option><option value="substitution"><?= e(t('Substitute an item')) ?></option><option value="other"><?= e(t('Something else')) ?></option></select>
                            </label>
                            <label class="field"><span><?= e(t('Explain')) ?></span><input type="text" name="description" maxlength="500" required></label>
                            <label class="field"><span><?= e(t('New total budget (৳), for more budget')) ?></span><input type="text" name="new_budget" inputmode="decimal"></label>
                            <button class="btn btn--ghost" type="submit"><?= e(t('Send request')) ?></button>
                        </form>
                    </details>
                <?php endif; ?>
                <form method="post" action="<?= e(url($base . '/deliver')) ?>"><?= csrf_field() ?><button class="btn btn--block btn--lg" type="submit" <?= $pending !== null ? 'disabled' : '' ?>><?= e(t('On the way to deliver')) ?></button></form>
            <?php elseif ($status === TaskStatus::DELIVERING): ?>
                <form method="post" action="<?= e(url($base . '/complete')) ?>" class="form">
                    <?= csrf_field() ?>
                    <label class="field"><span><?= e(t('Customer\'s 4-digit delivery code')) ?></span><input type="text" name="code" inputmode="numeric" pattern="\d{4}" maxlength="4" required class="input-code" autocomplete="off"></label>
                    <p class="muted small"><?= e(t('Ask for the code only after the customer has checked the items. Wrong tries left: :n', ['n' => max(0, App\Services\TaskFlowService::MAX_CODE_TRIES - (int) $task['code_attempts'])])) ?></p>
                    <button class="btn btn--block btn--lg" type="submit"><?= e(t('Complete task')) ?></button>
                </form>
                <details>
                    <summary><?= e(t('Customer not available?')) ?></summary>
                    <form method="post" action="<?= e(url($base . '/no-code')) ?>" enctype="multipart/form-data" class="form">
                        <?= csrf_field() ?>
                        <label class="field"><span><?= e(t('Photo of the delivered items')) ?></span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>
                        <label class="field"><span><?= e(t('Who received it / where?')) ?></span><input type="text" name="note" maxlength="255"></label>
                        <button class="btn btn--ghost" type="submit"><?= e(t('Mark delivered without code')) ?></button>
                    </form>
                </details>
            <?php elseif ($status === TaskStatus::AWAITING): ?>
                <p><?= e(t('Waiting for the customer to confirm. It completes automatically after :h hours if no problem is reported.', ['h' => setting('auto_complete_hours')])) ?></p>
            <?php elseif ($status === TaskStatus::COMPLETED && $review === null && strtotime((string) $task['completed_at']) > time() - 7 * 86400): ?>
                <form method="post" action="<?= e(url($base . '/rate')) ?>" class="form">
                    <?= csrf_field() ?>
                    <h2><?= e(t('Rate the customer')) ?></h2>
                    <div class="stars" role="radiogroup"><?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" name="rating" value="<?= $i ?>" id="star<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>><label for="star<?= $i ?>">★</label><?php endfor; ?></div>
                    <label class="field"><span><?= e(t('Comment (optional)')) ?></span><input type="text" name="comment" maxlength="500"></label>
                    <button class="btn" type="submit"><?= e(t('Send rating')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($task['is_active'] || ($task['is_mine'] && $status === TaskStatus::DISPUTED)): ?>
        <?= partial('components/chat', ['number' => $number, 'canWrite' => true]) ?>
    <?php endif; ?>
    <?php if ($task['is_active']): ?><?= partial('components/sos', ['number' => $number]) ?><?php endif; ?>

    <?php if ($task['is_mine'] && $status === TaskStatus::ASSIGNED): ?>
        <details class="card card--quiet">
            <summary class="danger-text"><?= e(t('Cannot do this task?')) ?></summary>
            <form method="post" action="<?= e(url($base . '/drop')) ?>" class="form" data-confirm="<?= e(t('Release this task? It counts as a strike.')) ?>">
                <?= csrf_field() ?>
                <p class="small"><?= e(t('Releasing a task you accepted counts as a strike. :n strikes in 30 days pause your account.', ['n' => setting('strike_limit')])) ?></p>
                <label class="field"><span><?= e(t('Reason')) ?></span><input type="text" name="reason" maxlength="200"></label>
                <button class="btn btn--danger" type="submit"><?= e(t('Release task')) ?></button>
            </form>
        </details>
    <?php endif; ?>
</section>
