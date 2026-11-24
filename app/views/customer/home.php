<?php $user = current_user(); ?>
<section class="container page">
    <h1 class="page__title"><?= e(t('Hi :name, what do you need today?', ['name' => strtok((string) $user['name'], ' ')])) ?></h1>
    <?php if (!$verified): ?>
        <p class="note"><?= e(t('Verify your mobile number to post tasks.')) ?> <a href="<?= e(url('/verify-phone')) ?>"><?= e(t('Verify now')) ?></a></p>
    <?php endif; ?>
    <div class="cat-grid cat-grid--compact">
        <?php foreach ($categories as $c): ?>
            <a class="cat-card" href="<?= e(url('/app/tasks/new/' . $c['code'])) ?>">
                <span class="cat-card__icon"><?= partial('components/icon', ['name' => $c['icon']]) ?></span>
                <span class="cat-card__name"><?= e(local_name($c)) ?></span>
                <span class="cat-card__price"><?= $c['pricing_mode'] === 'offer' ? e(t('From')) . ' ' : '' ?><?= e(money_db($c['base_fee'], false)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="section-head">
        <h2 class="section-title"><?= e(t('Running tasks')) ?></h2>
        <a href="<?= e(url('/app/tasks')) ?>" class="small"><?= e(t('All tasks')) ?></a>
    </div>
    <?= partial('customer/tasks/_list', ['tasks' => $tasks, 'empty' => t('No running tasks. Pick a service above to start.')]) ?>
</section>
