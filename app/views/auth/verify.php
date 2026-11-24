<section class="auth">
    <div class="card auth__card">
        <h1><?= e(t('Verify your number')) ?></h1>
        <p class="muted"><?= e(t('Enter the 6-digit code we sent to :phone.', ['phone' => $phone])) ?></p>
        <form method="post" action="<?= e(url('/verify-phone')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span><?= e(t('Code')) ?></span>
                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus class="input-code">
            </label>
            <button class="btn btn--block" type="submit"><?= e(t('Verify')) ?></button>
        </form>
        <form method="post" action="<?= e(url('/verify-phone/resend')) ?>" class="auth__switch">
            <?= csrf_field() ?>
            <button class="link-button" type="submit"><?= e(t('Send a new code')) ?></button>
        </form>
    </div>
</section>
