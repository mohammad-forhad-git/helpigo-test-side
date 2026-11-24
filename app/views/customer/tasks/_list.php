<?php use App\Services\TaskStatus; ?>
<?php if ($tasks === []): ?>
    <div class="empty">
        <?= partial('components/icon', ['name' => 'list']) ?>
        <p><?= e($empty) ?></p>
    </div>
<?php else: ?>
    <ul class="list">
        <?php foreach ($tasks as $task): ?>
            <li>
                <a class="list__row list__row--link" href="<?= e(url('/app/tasks/' . $task['task_number'])) ?>">
                    <span class="list__icon"><?= partial('components/icon', ['name' => $task['category_icon']]) ?></span>
                    <span class="list__main">
                        <strong><?= e($task['title']) ?></strong>
                        <span class="muted small"><?= e($task['category_name']) ?> · <?= e(format_datetime($task['preferred_at'] ?? $task['created_at'])) ?></span>
                    </span>
                    <span class="badge badge--<?= e($task['status']) ?>"><?= e(t(TaskStatus::label($task['status']))) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
