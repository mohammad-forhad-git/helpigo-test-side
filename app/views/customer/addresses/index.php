<section class="container page page--narrow">
    <a class="back-link" href="<?= e(url('/app/account')) ?>">← <?= e(t('Account')) ?></a>
    <div class="section-head">
        <h1 class="page__title"><?= e(t('My addresses')) ?></h1>
        <a class="btn btn--sm" href="<?= e(url('/app/addresses/new')) ?>"><?= partial('components/icon', ['name' => 'plus']) ?><?= e(t('Add')) ?></a>
    </div>
    <?php if ($addresses === []): ?>
        <div class="empty"><p><?= e(t('No saved addresses yet.')) ?></p></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($addresses as $a): ?>
                <li class="list__row">
                    <span class="list__main">
                        <strong><?= e($a['label']) ?></strong> <span class="muted small">· <?= e($a['zone_name']) ?></span>
                        <span class="small"><?= e($a['address_line']) ?></span>
                        <?php if ($a['contact_name']): ?><span class="muted small"><?= e(t('For')) ?> <?= e($a['contact_name']) ?> · <?= e($a['contact_phone']) ?></span><?php endif; ?>
                    </span>
                    <span class="row-actions">
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('/app/addresses/' . $a['id'] . '/edit')) ?>"><?= e(t('Edit')) ?></a>
                        <form method="post" action="<?= e(url('/app/addresses/' . $a['id'] . '/delete')) ?>" data-confirm="<?= e(t('Delete this address?')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--ghost btn--sm" type="submit"><?= e(t('Delete')) ?></button>
                        </form>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
