<?php use App\Services\TaskStatus; ?>
<section class="container page page--narrow">
    <h1 class="page__title"><?= e(t('My tasks')) ?></h1>
    <nav class="segmented" aria-label="<?= e(t('Filter tasks')) ?>">
        <a href="<?= e(url('/worker/tasks')) ?>" class="<?= !$past ? 'is-active' : '' ?>"><?= e(t('Current')) ?></a>
        <a href="<?= e(url('/worker/tasks', ['show' => 'past'])) ?>" class="<?= $past ? 'is-active' : '' ?>"><?= e(t('Past')) ?></a>
    </nav>
    <?php if ($tasks === []): ?>
        <div class="empty"><?= partial('components/icon', ['name' => 'list']) ?><p><?= e($past ? t('No past tasks yet.') : t('No current task. Find one on the Home tab.')) ?></p></div>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($tasks as $task): ?>
                <li><a class="list__row list__row--link" href="<?= e(url('/worker/tasks/' . $task['task_number'])) ?>">
                    <span class="list__icon"><?= partial('components/icon', ['name' => $task['category_icon']]) ?></span>
                    <span class="list__main"><strong><?= e($task['title']) ?></strong><span class="muted small"><?= e($task['task_number']) ?> · <?= e(format_datetime($task['completed_at'] ?? $task['created_at'])) ?></span></span>
                    <span class="badge badge--<?= e($task['status']) ?>"><?= e(t(TaskStatus::label($task['status']))) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
