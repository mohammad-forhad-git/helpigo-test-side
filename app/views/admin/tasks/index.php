<?php use App\Services\TaskStatus; ?>
<h1 class="page__title">Tasks</h1>
<form method="get" action="<?= e(url('/admin/tasks')) ?>" class="inline-form filter-bar">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Task number, title or phone">
    <select name="status">
        <option value="">Running (all active)</option>
        <?php foreach (TaskStatus::all() as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(TaskStatus::label($s)) ?></option><?php endforeach; ?>
    </select>
    <label class="check"><input type="checkbox" name="view" value="all" <?= $view === 'all' ? 'checked' : '' ?>><span>Include closed</span></label>
    <button class="btn btn--sm" type="submit">Filter</button>
</form>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Task</th><th>Service · Zone</th><th>Customer</th><th>Helper</th><th>Status</th><th>Created</th><th class="num">Fee</th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t): ?>
        <tr>
            <td><a href="<?= e(url('/admin/tasks/' . $t['task_number'])) ?>"><?= e($t['task_number']) ?></a><br><small><?= e($t['title']) ?></small></td>
            <td><?= e($t['category_name']) ?><br><small class="muted"><?= e($t['zone_name']) ?></small></td>
            <td><?= e($t['customer_name']) ?></td>
            <td><?= e($t['worker_name'] ?? '–') ?></td>
            <td><span class="badge badge--<?= e($t['status']) ?>"><?= e(TaskStatus::label($t['status'])) ?></span><?= (int) $t['item_prepaid'] === 1 ? ' <small>prepaid</small>' : '' ?></td>
            <td><?= e(format_datetime($t['created_at'])) ?></td>
            <td class="num"><?= e(money_db($t['worker_fee'], false)) ?> <small class="muted"><?= e($t['payment_method']) ?></small></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($tasks === []): ?><tr><td colspan="7" class="muted">No tasks.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
