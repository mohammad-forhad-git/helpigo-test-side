<?php $user = current_user(); ?>
<section class="container page page--narrow">
    <h1 class="page__title"><?= e(t('Settings')) ?></h1>
    <form method="post" action="<?= e(url('/settings')) ?>" class="card form">
        <?= csrf_field() ?>
        <?php if ($user['role'] !== 'admin'): ?>
            <div class="field">
                <span><?= e(t('Language')) ?></span>
                <div class="segmented segmented--form">
                    <label><input type="radio" name="locale" value="en" <?= $user['locale'] !== 'bn' ? 'checked' : '' ?>><span>English</span></label>
                    <label><input type="radio" name="locale" value="bn" <?= $user['locale'] === 'bn' ? 'checked' : '' ?>><span>বাংলা</span></label>
                </div>
            </div>
        <?php endif; ?>
        <fieldset class="form-sub">
            <legend><?= e(t('Change password')) ?> <small class="muted">(<?= e(t('optional')) ?>)</small></legend>
            <label class="field"><span><?= e(t('Current password')) ?></span><input type="password" name="current_password" autocomplete="current-password"></label>
            <label class="field"><span><?= e(t('New password')) ?></span><input type="password" name="password" autocomplete="new-password"></label>
            <label class="field"><span><?= e(t('Repeat password')) ?></span><input type="password" name="password_confirmation" autocomplete="new-password"></label>
        </fieldset>
        <button class="btn btn--block" type="submit"><?= e(t('Save')) ?></button>
    </form>
</section>
