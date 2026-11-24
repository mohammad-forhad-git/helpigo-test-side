<?php
$val = static fn (string $key): mixed => old($key, $profile[$key] ?? '');
$err = static function (string $key): string {
    $error = field_error($key);
    return $error !== null ? '<span class="field__error">' . e(t($error)) . '</span>' : '';
};
$zonesChecked = array_map('intval', (array) old('zones', $profile['zone_ids']));
$catsChecked = array_map('intval', (array) old('categories', $profile['category_ids']));
?>
<section class="container page page--narrow">
    <a class="back-link" href="<?= e(url('/worker')) ?>">← <?= e(t('Home')) ?></a>
    <h1 class="page__title"><?= e(t('Helper profile')) ?></h1>
    <?php if ($profile['status'] === 'approved'): ?>
        <p class="note"><?= e(t('Changing your National ID, gender or photos sends your profile back for review. Areas, services and wallet can be changed any time.')) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/worker/profile')) ?>" enctype="multipart/form-data" class="form" novalidate>
        <?= csrf_field() ?>
        <fieldset class="card form-section">
            <legend><?= e(t('About you')) ?></legend>
            <div class="field">
                <span><?= e(t('Gender')) ?></span>
                <div class="segmented segmented--form">
                    <?php foreach (['female' => t('Female'), 'male' => t('Male'), 'other' => t('Other')] as $k => $label): ?>
                        <label><input type="radio" name="gender" value="<?= e($k) ?>" <?= $val('gender') === $k ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?= $err('gender') ?>
            </div>
            <label class="field"><span><?= e(t('National ID number')) ?></span><input type="text" name="nid_number" inputmode="numeric" maxlength="17" value="<?= e($val('nid_number')) ?>" required><?= $err('nid_number') ?></label>
            <label class="field">
                <span><?= e(t('Photo of your National ID (front)')) ?><?= $profile['nid_image'] ? ' <small class="muted">— ' . e(t('saved; add a new one only to replace it')) . '</small>' : '' ?></span>
                <input type="file" name="nid_image" accept="image/jpeg,image/png,image/webp"><?= $err('nid_image') ?>
            </label>
            <label class="field">
                <span><?= e(t('A clear photo of your face')) ?><?= $profile['selfie_image'] ? ' <small class="muted">— ' . e(t('saved')) . '</small>' : '' ?></span>
                <input type="file" name="selfie_image" accept="image/jpeg,image/png,image/webp" capture="user"><?= $err('selfie_image') ?>
                <small class="muted"><?= e(t('Customers see this photo when you accept their task.')) ?></small>
            </label>
            <label class="field"><span><?= e(t('Reference: name of someone who knows you')) ?></span><input type="text" name="reference_name" maxlength="100" value="<?= e($val('reference_name')) ?>" required><?= $err('reference_name') ?></label>
            <label class="field"><span><?= e(t('Reference mobile number')) ?></span><input type="tel" name="reference_phone" inputmode="numeric" value="<?= e($val('reference_phone')) ?>" required><?= $err('reference_phone') ?><small class="muted"><?= e(t('Our team will call this person.')) ?></small></label>
        </fieldset>

        <fieldset class="card form-section">
            <legend><?= e(t('Where and what')) ?></legend>
            <div class="field">
                <span><?= e(t('Areas you can work in')) ?></span>
                <div class="chips">
                    <?php foreach ($zones as $z): ?>
                        <label class="chip"><input type="checkbox" name="zones[]" value="<?= (int) $z['id'] ?>" <?= in_array((int) $z['id'], $zonesChecked, true) ? 'checked' : '' ?>><span><?= e(local_name($z)) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?= $err('zones') ?>
            </div>
            <div class="field">
                <span><?= e(t('Services you offer')) ?></span>
                <div class="chips">
                    <?php foreach ($categories as $c): ?>
                        <label class="chip"><input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $catsChecked, true) ? 'checked' : '' ?>><span><?= e(local_name($c)) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?= $err('categories') ?>
            </div>
        </fieldset>

        <fieldset class="card form-section">
            <legend><?= e(t('Payout wallet')) ?></legend>
            <div class="segmented segmented--form">
                <?php foreach (['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket'] as $k => $label): ?>
                    <label><input type="radio" name="payout_method" value="<?= e($k) ?>" <?= $val('payout_method') === $k ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
            <?= $err('payout_method') ?>
            <label class="field"><span><?= e(t('Wallet number')) ?></span><input type="tel" name="payout_number" inputmode="numeric" value="<?= e($val('payout_number')) ?>" required><?= $err('payout_number') ?></label>
        </fieldset>

        <label class="check"><input type="checkbox" name="age_confirm" value="1" <?= old('age_confirm', $profile['nid_number'] ? '1' : '') === '1' ? 'checked' : '' ?>><span><?= e(t('I am 18 or older and agree to the')) ?> <a href="<?= e(url('/helper-agreement')) ?>" target="_blank"><?= e(t('Helper Agreement')) ?></a>.</span></label>
        <?= $err('age_confirm') ?>
        <button class="btn btn--block btn--lg" type="submit"><?= e($profile['status'] === 'approved' ? t('Save') : t('Send for review')) ?></button>
    </form>
</section>
