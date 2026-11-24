<section class="auth">
    <div class="card auth__card">
        <h1><?= e(t('Log in')) ?></h1>
        <p class="muted"><?= e(t('Customers, helpers and staff all log in here.')) ?></p>
        <form method="post" action="<?= e(url('/login')) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <label class="field">
                <span><?= e(t('Mobile number')) ?></span>
                <input type="tel" name="phone" inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" value="<?= e(old('phone')) ?>" required autofocus>
            </label>
            <label class="field">
                <span><?= e(t('Password')) ?></span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="btn btn--block" type="submit"><?= e(t('Log in')) ?></button>
        </form>
        <p class="auth__switch"><a href="<?= e(url('/forgot-password')) ?>"><?= e(t('Forgot password?')) ?></a></p>
        <p class="auth__switch"><?= e(t('New to Helpigo?')) ?> <a href="<?= e(url('/register')) ?>"><?= e(t('Create an account')) ?></a></p>
    </div>
</section>
