<?php /* SOS / safety report for an active task. Var: $number */ ?>
<details class="card card--quiet sos">
    <summary><?= e(t('Safety problem? Press SOS')) ?></summary>
    <form method="post" action="<?= e(url('/sos/' . $number)) ?>" class="form" data-confirm="<?= e(t('Alert Helpigo support now?')) ?>">
        <?= csrf_field() ?>
        <p class="small"><?= e(t('In an emergency call 999 first.')) ?> <?php if (setting('support_phone')): ?><?= e(t('Support')) ?>: <a href="tel:<?= e(setting('support_phone')) ?>"><?= e(setting('support_phone')) ?></a><?php endif; ?></p>
        <label class="field"><span><?= e(t('What is happening? (optional)')) ?></span><input type="text" name="description" maxlength="500"></label>
        <button class="btn btn--danger btn--block" type="submit">SOS</button>
    </form>
</details>
