<?php $role = old('role', $role); ?>
<section class="auth">
    <div class="card auth__card">
        <h1><?= e(t('Create account')) ?></h1>
        <form method="post" action="<?= e(url('/register')) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <fieldset class="role-pick">
                <legend><?= e(t('I want to')) ?></legend>
                <label class="role-pick__opt">
                    <input type="radio" name="role" value="customer" <?= $role !== 'worker' ? 'checked' : '' ?>>
                    <span><?= partial('components/icon', ['name' => 'task']) ?><strong><?= e(t('Get help')) ?></strong><small><?= e(t('Post tasks and hire helpers')) ?></small></span>
                </label>
                <label class="role-pick__opt">
                    <input type="radio" name="role" value="worker" <?= $role === 'worker' ? 'checked' : '' ?>>
                    <span><?= partial('components/icon', ['name' => 'wallet']) ?><strong><?= e(t('Earn as a helper')) ?></strong><small><?= e(t('Complete tasks near you')) ?></small></span>
                </label>
            </fieldset>
            <label class="field">
                <span><?= e(t('Full name')) ?></span>
                <input type="text" name="name" autocomplete="name" maxlength="100" value="<?= e(old('name')) ?>" required>
            </label>
            <label class="field">
                <span><?= e(t('Mobile number')) ?></span>
                <input type="tel" name="phone" inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" value="<?= e(old('phone')) ?>" required>
            </label>
            <label class="field">
                <span><?= e(t('Password')) ?> <small class="muted">(<?= e(t('at least :n characters', ['n' => App\Services\AuthService::MIN_PASSWORD])) ?>)</small></span>
                <input type="password" name="password" autocomplete="new-password" minlength="<?= App\Services\AuthService::MIN_PASSWORD ?>" required>
            </label>
            <label class="field">
                <span><?= e(t('Repeat password')) ?></span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
            </label>
            <label class="field" data-role-only="customer">
                <span><?= e(t('Referral code')) ?> <small class="muted">(<?= e(t('optional')) ?>)</small></span>
                <input type="text" name="referral" maxlength="10" autocapitalize="characters" value="<?= e(old('referral', $referral ?? '')) ?>">
            </label>
            <label class="check">
                <input type="checkbox" name="agree" value="1" required>
                <span><?= e(t('I agree to the')) ?> <a href="<?= e(url('/terms')) ?>" target="_blank"><?= e(t('Terms of Use')) ?></a> <?= e(t('and')) ?> <a href="<?= e(url('/privacy')) ?>" target="_blank"><?= e(t('Privacy Policy')) ?></a>.</span>
            </label>
            <button class="btn btn--block" type="submit"><?= e(t('Create account')) ?></button>
        </form>
        <p class="auth__switch"><?= e(t('Already have an account?')) ?> <a href="<?= e(url('/login')) ?>"><?= e(t('Log in')) ?></a></p>
    </div>
</section>
