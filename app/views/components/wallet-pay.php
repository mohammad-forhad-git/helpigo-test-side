<?php
/* Wallet payment form. Vars: $action (URL), $amount (paisa), $purpose (hidden value or null), $title */
use App\Services\PaymentService;

$accounts = PaymentService::accounts();
$err = static function (string $key): string {
    $error = field_error($key);
    return $error !== null ? '<span class="field__error">' . e(t($error)) . '</span>' : '';
};
?>
<div class="card pay-card" id="pay">
    <h2><?= e($title) ?>: <?= e(money($amount)) ?></h2>
    <?php if ($accounts === []): ?>
        <p class="note"><?= e(t('Wallet payment is not set up yet. Please pay in cash or contact support.')) ?></p>
    <?php else: ?>
        <ol class="small steps-list">
            <li><?= e(t('Open your bKash or Nagad app and use "Send Money" to:')) ?>
                <?php foreach ($accounts as $method => $number): ?>
                    <br><strong><?= e($method === 'bkash' ? 'bKash' : 'Nagad') ?>: <?= e($number) ?></strong>
                <?php endforeach; ?>
            </li>
            <li><?= e(t('Send exactly :amount.', ['amount' => money($amount)])) ?></li>
            <li><?= e(t('Enter the transaction ID from the confirmation SMS below.')) ?></li>
        </ol>
        <form method="post" action="<?= e(url($action)) ?>" class="form">
            <?= csrf_field() ?>
            <?php if ($purpose !== null): ?><input type="hidden" name="purpose" value="<?= e($purpose) ?>"><?php endif; ?>
            <div class="segmented segmented--form">
                <?php foreach ($accounts as $method => $number): ?>
                    <label><input type="radio" name="method" value="<?= e($method) ?>" <?= old('method', array_key_first($accounts)) === $method ? 'checked' : '' ?>><span><?= e($method === 'bkash' ? 'bKash' : 'Nagad') ?></span></label>
                <?php endforeach; ?>
            </div>
            <label class="field"><span><?= e(t('Your wallet number')) ?></span><input type="tel" name="sender_number" inputmode="numeric" placeholder="01XXXXXXXXX" value="<?= e(old('sender_number')) ?>" required><?= $err('sender_number') ?></label>
            <label class="field"><span><?= e(t('Transaction ID')) ?></span><input type="text" name="trx_id" autocapitalize="characters" maxlength="30" value="<?= e(old('trx_id')) ?>" required><?= $err('trx_id') ?></label>
            <button class="btn btn--block" type="submit"><?= e(t('Submit payment')) ?></button>
        </form>
    <?php endif; ?>
</div>
