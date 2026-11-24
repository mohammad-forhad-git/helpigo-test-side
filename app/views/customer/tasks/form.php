<?php
use App\Services\Money;
use App\Services\TaskService;

$purchase = (int) $category['involves_purchase'] === 1;
$isOffer = $category['pricing_mode'] === 'offer';
$names = (array) old('item_name', []);
$qtys = (array) old('item_qty', []);
$notes = (array) old('item_note', []);
$rows = max(3, count($names));
$when = old('when', 'asap');
$payment = old('payment_method', 'cash');
$selectedAddress = (int) old('address_id', $addresses[0]['id'] ?? 0);
$pickupHint = match ($category['code']) {
    'medicine'     => t('e.g. Lazz Pharma, Road 27 Dhanmondi — or "any licensed pharmacy nearby"'),
    'fresh_market' => t('e.g. Mohammadpur Krishi Market'),
    'pickup_drop'  => t('Where the helper collects the item'),
    default        => t('Shop name and area, or "any nearby shop"'),
};
$fieldError = static function (string $key): string {
    $error = field_error($key);
    return $error !== null ? '<span class="field__error">' . e(t($error)) . '</span>' : '';
};
?>
<section class="container page page--narrow">
    <a class="back-link" href="<?= e(url('/app/tasks/new')) ?>">← <?= e(t('All services')) ?></a>
    <div class="form-head">
        <span class="cat-card__icon"><?= partial('components/icon', ['name' => $category['icon']]) ?></span>
        <div>
            <h1 class="page__title"><?= e(local_name($category)) ?></h1>
            <p class="muted small"><?= e($category['description']) ?></p>
        </div>
    </div>

    <form method="post" action="<?= e(url('/app/tasks/new/' . $category['code'])) ?>" enctype="multipart/form-data" class="form" id="task-form"
          data-mode="<?= e($category['pricing_mode']) ?>"
          data-base="<?= Money::fromDecimal((string) $category['base_fee']) ?>"
          data-included="<?= (int) $category['included_items'] ?>"
          data-per-item="<?= Money::fromDecimal((string) $category['per_item_fee']) ?>"
          data-platform="<?= Money::fromDecimal((string) setting('platform_fee')) ?>"
          data-credit="<?= (int) ($credit ?? 0) ?>">
        <?= csrf_field() ?>

        <fieldset class="card form-section">
            <legend><?= e(t('What do you need?')) ?></legend>
            <label class="field">
                <span><?= e(t('Short title')) ?></span>
                <input type="text" name="title" maxlength="120" required value="<?= e(old('title')) ?>"
                       placeholder="<?= e($purchase ? t('e.g. Weekly groceries for Ammu') : t('e.g. Collect documents from office')) ?>">
                <?= $fieldError('title') ?>
            </label>

            <?php if ($purchase): ?>
                <div class="items" data-max="<?= TaskService::MAX_ITEMS ?>">
                    <div class="items__head"><span><?= e(t('Item')) ?></span><span><?= e(t('Quantity')) ?></span></div>
                    <div class="items__rows" id="item-rows">
                        <?php for ($i = 0; $i < $rows; $i++): ?>
                            <div class="item-row">
                                <input type="text" name="item_name[]" maxlength="120" aria-label="<?= e(t('Item name')) ?>" placeholder="<?= e($i === 0 ? t('e.g. Rice (miniket)') : '') ?>" value="<?= e($names[$i] ?? '') ?>">
                                <input type="text" name="item_qty[]" maxlength="40" aria-label="<?= e(t('Quantity')) ?>" placeholder="<?= e($i === 0 ? t('5 kg') : '') ?>" value="<?= e($qtys[$i] ?? '') ?>">
                                <input type="text" name="item_note[]" maxlength="255" class="item-row__note" aria-label="<?= e(t('Note')) ?>" placeholder="<?= e(t('Brand or note (optional)')) ?>" value="<?= e($notes[$i] ?? '') ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                    <button type="button" class="btn btn--ghost btn--sm" id="add-item" hidden><?= partial('components/icon', ['name' => 'plus']) ?><?= e(t('Add item')) ?></button>
                    <?= $fieldError('items') ?>
                </div>
                <label class="field">
                    <span><?= e(t('Or add a photo of your list')) ?> <small class="muted">(<?= e(t('up to :n', ['n' => TaskService::MAX_LIST_PHOTOS])) ?>)</small></span>
                    <input type="file" name="list_photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                </label>
            <?php endif; ?>

            <?php if ((int) $category['allows_prescription'] === 1): ?>
                <label class="check">
                    <input type="checkbox" name="has_prescription" value="1" id="has-prescription" <?= old('has_prescription') === '1' ? 'checked' : '' ?>>
                    <span><?= e(t('Some medicines need a prescription')) ?></span>
                </label>
                <label class="field" id="prescription-field">
                    <span><?= e(t('Prescription photo')) ?> <small class="muted">(<?= e(t('required for prescription medicines')) ?>)</small></span>
                    <input type="file" name="prescription_photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                </label>
            <?php endif; ?>

            <label class="field">
                <span><?= e(t('Details for the helper')) ?> <small class="muted">(<?= e(t('optional')) ?>)</small></span>
                <textarea name="description" rows="3" maxlength="1000" placeholder="<?= e(t('Preferred brands, what to do if an item is missing, gate instructions…')) ?>"><?= e(old('description')) ?></textarea>
                <?= $fieldError('description') ?>
            </label>
        </fieldset>

        <fieldset class="card form-section">
            <legend><?= e(t('Where?')) ?></legend>
            <label class="field">
                <span><?= e($purchase ? t('Shop or market') : t('Pick-up point')) ?><?= $category['code'] === 'custom' ? ' <small class="muted">(' . e(t('optional')) . ')</small>' : '' ?></span>
                <input type="text" name="pickup_location" maxlength="255" value="<?= e(old('pickup_location')) ?>" placeholder="<?= e($pickupHint) ?>" <?= $category['code'] === 'custom' ? '' : 'required' ?>>
                <?= $fieldError('pickup_location') ?>
            </label>
            <div class="field">
                <span><?= e(t('Deliver to')) ?></span>
                <div class="choice-list">
                    <?php foreach ($addresses as $a): ?>
                        <label class="choice">
                            <input type="radio" name="address_id" value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $selectedAddress ? 'checked' : '' ?>>
                            <span>
                                <strong><?= e($a['label']) ?></strong> · <?= e($a['zone_name']) ?><br>
                                <small class="muted"><?= e($a['address_line']) ?><?= $a['contact_name'] ? ' · ' . e(t('for')) . ' ' . e($a['contact_name']) : '' ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <a class="small" href="<?= e(url('/app/addresses/new', ['back' => '/app/tasks/new/' . $category['code']])) ?>">+ <?= e(t('Add another address')) ?></a>
                <?= $fieldError('address_id') ?>
            </div>
        </fieldset>

        <fieldset class="card form-section">
            <legend><?= e(t('When?')) ?></legend>
            <div class="segmented segmented--form">
                <label><input type="radio" name="when" value="asap" <?= $when !== 'later' ? 'checked' : '' ?>><span><?= e(t('As soon as possible')) ?></span></label>
                <label><input type="radio" name="when" value="later" <?= $when === 'later' ? 'checked' : '' ?>><span><?= e(t('Schedule')) ?></span></label>
            </div>
            <label class="field" id="preferred-at-field">
                <span><?= e(t('Date and time')) ?></span>
                <input type="datetime-local" name="preferred_at" value="<?= e(old('preferred_at')) ?>"
                       min="<?= e(date('Y-m-d\TH:i', time() + 1800)) ?>" max="<?= e(date('Y-m-d\TH:i', time() + 7 * 86400)) ?>">
                <?= $fieldError('preferred_at') ?>
            </label>
            <p class="muted small"><?= e(t('Service hours')) ?>: <?= e(setting('service_hours')) ?></p>
        </fieldset>

        <fieldset class="card form-section">
            <legend><?= e(t('Money')) ?></legend>
            <?php if ($purchase): ?>
                <label class="field">
                    <span><?= e(t('Item budget (৳)')) ?></span>
                    <input type="text" name="item_budget" inputmode="decimal" required value="<?= e(old('item_budget')) ?>" placeholder="1000">
                    <small class="muted"><?= e(t('The most the helper may spend on items. You pay the receipt amount, never more without your approval. Maximum :max.', ['max' => money_db(setting('float_tier3'), false)])) ?></small>
                    <?= $fieldError('item_budget') ?>
                </label>
            <?php endif; ?>
            <?php if ($isOffer): ?>
                <label class="field">
                    <span><?= e(t('Your fee offer for the helper (৳)')) ?></span>
                    <input type="text" name="offer_fee" inputmode="decimal" required value="<?= e(old('offer_fee', (string) (int) $category['base_fee'])) ?>">
                    <small class="muted"><?= e(t('Minimum :min. Helpers may suggest a different fee; you choose.', ['min' => money_db($category['base_fee'], false)])) ?></small>
                    <?= $fieldError('offer_fee') ?>
                </label>
            <?php endif; ?>
            <div class="field">
                <span><?= e(t('How will you pay?')) ?></span>
                <div class="segmented segmented--form">
                    <label><input type="radio" name="payment_method" value="cash" <?= $payment === 'cash' ? 'checked' : '' ?>><span><?= e(t('Cash')) ?></span></label>
                    <label><input type="radio" name="payment_method" value="bkash" <?= $payment === 'bkash' ? 'checked' : '' ?>><span>bKash</span></label>
                    <label><input type="radio" name="payment_method" value="nagad" <?= $payment === 'nagad' ? 'checked' : '' ?>><span>Nagad</span></label>
                </div>
            </div>
            <label class="check">
                <input type="checkbox" name="prefer_female" value="1" <?= old('prefer_female') === '1' ? 'checked' : '' ?>>
                <span><?= e(t('I prefer a female helper')) ?></span>
            </label>
        </fieldset>

        <div class="card summary" aria-live="polite">
            <h2><?= e(t('Price')) ?></h2>
            <dl class="summary__rows">
                <div><dt><?= e(t('Helper fee')) ?></dt><dd id="sum-worker">—</dd></div>
                <div><dt><?= e(t('Platform fee')) ?></dt><dd id="sum-platform">—</dd></div>
                <div id="sum-discount-row" hidden><dt><?= e(t('Your credit')) ?></dt><dd id="sum-discount">—</dd></div>
                <div class="summary__total"><dt><?= e(t('Total fees')) ?></dt><dd id="sum-total">—</dd></div>
            </dl>
            <?php if ($purchase): ?>
                <p class="muted small"><?= e(t('Plus the items at the receipt price, up to your budget.')) ?></p>
            <?php endif; ?>
            <p class="muted small"><?= e(t('Free to cancel until a helper accepts.')) ?></p>
            <button class="btn btn--block btn--lg" type="submit"><?= e(t('Post task')) ?></button>
        </div>
    </form>
</section>
