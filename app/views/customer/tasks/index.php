<section class="container page">
    <div class="section-head">
        <h1 class="page__title"><?= e(t('My tasks')) ?></h1>
        <a class="btn btn--sm" href="<?= e(url('/app/tasks/new')) ?>"><?= partial('components/icon', ['name' => 'plus']) ?><?= e(t('New task')) ?></a>
    </div>
    <nav class="segmented" aria-label="<?= e(t('Filter tasks')) ?>">
        <a href="<?= e(url('/app/tasks')) ?>" class="<?= $filter === 'active' ? 'is-active' : '' ?>"<?= $filter === 'active' ? ' aria-current="page"' : '' ?>><?= e(t('Running')) ?></a>
        <a href="<?= e(url('/app/tasks', ['show' => 'past'])) ?>" class="<?= $filter === 'past' ? 'is-active' : '' ?>"<?= $filter === 'past' ? ' aria-current="page"' : '' ?>><?= e(t('Past')) ?></a>
    </nav>
    <?= partial('customer/tasks/_list', ['tasks' => $tasks, 'empty' => $filter === 'past' ? t('No past tasks yet.') : t('No running tasks.')]) ?>
</section>
