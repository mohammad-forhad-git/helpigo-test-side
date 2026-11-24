<section class="auth">
    <div class="card auth__card">
        <h1><?= e(t('Forgot password')) ?></h1>
        <p class="muted"><?= e(t('We will send a code to your mobile number.')) ?></p>
        <form method="post" action="<?= e(url('/forgot-password')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span><?= e(t('Mobile number')) ?></span>
                <input type="tel" name="phone" inputmode="numeric" placeholder="01XXXXXXXXX" value="<?= e(old('phone')) ?>" required autofocus>
            </label>
            <button class="btn btn--block" type="submit"><?= e(t('Send code')) ?></button>
        </form>
        <p class="auth__switch"><a href="<?= e(url('/login')) ?>"><?= e(t('Back to log in')) ?></a></p>
    </div>
</section>
