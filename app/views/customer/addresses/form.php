<?php
$val = static fn (string $key): string => (string) old($key, $address[$key] ?? '');
$err = static function (string $key): string {
    $error = field_error($key);
    return $error !== null ? '<span class="field__error">' . e(t($error)) . '</span>' : '';
};
$action = $address ? '/app/addresses/' . $address['id'] : '/app/addresses';
?>
<section class="container page page--narrow">
    <a class="back-link" href="<?= e(url($back)) ?>">← <?= e(t('Back')) ?></a>
    <h1 class="page__title"><?= e($address ? t('Edit address') : t('Add address')) ?></h1>
    <form method="post" action="<?= e(url($action)) ?>" class="card form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <label class="field">
            <span><?= e(t('Label')) ?></span>
            <input type="text" name="label" maxlength="40" required list="address-labels" value="<?= e($val('label')) ?>" placeholder="<?= e(t('Home, Office, Ammu-Abbu…')) ?>">
            <datalist id="address-labels"><option value="Home"><option value="Office"><option value="Parents"></datalist>
            <?= $err('label') ?>
        </label>
        <label class="field">
            <span><?= e(t('Area')) ?></span>
            <select name="zone_id" required>
                <option value=""><?= e(t('Choose your area')) ?></option>
                <?php foreach ($zones as $z): ?>
                    <option value="<?= (int) $z['id'] ?>" <?= (string) $z['id'] === $val('zone_id') ? 'selected' : '' ?>><?= e(local_name($z)) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="muted"><?= e(t('We serve these areas during the pilot.')) ?></small>
            <?= $err('zone_id') ?>
        </label>
        <label class="field">
            <span><?= e(t('Address')) ?></span>
            <textarea name="address_line" rows="2" maxlength="255" required placeholder="<?= e(t('House, road, block, flat')) ?>"><?= e($val('address_line')) ?></textarea>
            <?= $err('address_line') ?>
        </label>
        <label class="field">
            <span><?= e(t('Landmark')) ?> <small class="muted">(<?= e(t('optional')) ?>)</small></span>
            <input type="text" name="landmark" maxlength="120" value="<?= e($val('landmark')) ?>" placeholder="<?= e(t('e.g. opposite the mosque')) ?>">
            <?= $err('landmark') ?>
        </label>
        <fieldset class="form-sub">
            <legend><?= e(t('Booking for someone else?')) ?> <small class="muted">(<?= e(t('optional')) ?>)</small></legend>
            <p class="muted small"><?= e(t('The helper will hand over to this person and call them on arrival.')) ?></p>
            <label class="field">
                <span><?= e(t('Their name')) ?></span>
                <input type="text" name="contact_name" maxlength="100" value="<?= e($val('contact_name')) ?>">
                <?= $err('contact_name') ?>
            </label>
            <label class="field">
                <span><?= e(t('Their mobile number')) ?></span>
                <input type="tel" name="contact_phone" inputmode="numeric" placeholder="01XXXXXXXXX" value="<?= e($val('contact_phone')) ?>">
                <?= $err('contact_phone') ?>
            </label>
        </fieldset>
        <button class="btn btn--block" type="submit"><?= e(t('Save address')) ?></button>
    </form>
</section>
