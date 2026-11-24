<section class="container page page--narrow">
    <h1 class="page__title"><?= e(t('Notifications')) ?></h1>
    <?php if ($items === []): ?>
        <div class="empty"><?= partial('components/icon', ['name' => 'list']) ?><p><?= e(t('Nothing yet.')) ?></p></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($items as $n): ?>
                <li>
                    <?php $inner = '<span class="list__main"><strong>' . e(t($n['title'])) . '</strong>' . ($n['body'] ? '<span class="small">' . e($n['body']) . '</span>' : '') . '<span class="muted small">' . e(format_datetime($n['created_at'])) . '</span></span>' . ($n['read_at'] === null ? '<span class="dot" aria-label="' . e(t('New')) . '"></span>' : ''); ?>
                    <?php if ($n['link']): ?>
                        <a class="list__row list__row--link" href="<?= e(url($n['link'])) ?>"><?= $inner ?></a>
                    <?php else: ?>
                        <div class="list__row"><?= $inner ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
