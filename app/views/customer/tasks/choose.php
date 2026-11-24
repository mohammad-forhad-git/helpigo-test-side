<section class="container page">
    <h1 class="page__title"><?= e(t('What do you need help with?')) ?></h1>
    <div class="cat-grid">
        <?php foreach ($categories as $c): ?>
            <a class="cat-card" href="<?= e(url('/app/tasks/new/' . $c['code'])) ?>">
                <span class="cat-card__icon"><?= partial('components/icon', ['name' => $c['icon']]) ?></span>
                <span class="cat-card__name"><?= e(local_name($c)) ?></span>
                <span class="cat-card__desc"><?= e($c['description']) ?></span>
                <span class="cat-card__price"><?= $c['pricing_mode'] === 'offer' ? e(t('From')) . ' ' : '' ?><?= e(money_db($c['base_fee'], false)) ?> <?= e(t('helper fee')) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
