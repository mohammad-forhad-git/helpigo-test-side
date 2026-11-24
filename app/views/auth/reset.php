<section class="auth">
    <div class="card auth__card">
        <h1><?= e(t('Set a new password')) ?></h1>
        <form method="post" action="<?= e(url('/reset-password')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span><?= e(t('Mobile number')) ?></span>
                <input type="tel" name="phone" inputmode="numeric" value="<?= e(old('phone')) ?>" required>
            </label>
            <label class="field">
                <span><?= e(t('Code')) ?></span>
                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="input-code">
            </label>
            <label class="field">
                <span><?= e(t('New password')) ?></span>
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label class="field">
                <span><?= e(t('Repeat password')) ?></span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
            </label>
            <button class="btn btn--block" type="submit"><?= e(t('Save password')) ?></button>
        </form>
        <p class="auth__switch"><a href="<?= e(url('/forgot-password')) ?>"><?= e(t('Send a new code')) ?></a></p>
    </div>
</section>
