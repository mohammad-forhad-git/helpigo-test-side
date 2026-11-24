<?php use App\Services\ComplaintService; ?>
<h1 class="page__title">Complaints</h1>
<p class="small muted">Disputed tasks are decided on the task page (that closes their complaints too). Use this page for other reports. Reply within 24 hours; decide within 72.</p>
<nav class="segmented">
    <a href="<?= e(url('/admin/complaints')) ?>" class="<?= $filter === 'open' ? 'is-active' : '' ?>">Open</a>
    <a href="<?= e(url('/admin/complaints', ['show' => 'closed'])) ?>" class="<?= $filter === 'closed' ? 'is-active' : '' ?>">Closed</a>
</nav>
<?php if ($complaints === []): ?><p class="muted">None.</p><?php endif; ?>
<?php foreach ($complaints as $c): ?>
    <div class="card<?= (int) $c['priority'] === 1 ? ' card--urgent' : '' ?>">
        <p><?= (int) $c['priority'] === 1 ? '<strong class="danger-text">SOS / SAFETY</strong> · ' : '' ?><strong><?= e(ComplaintService::TYPES[$c['type']] ?? $c['type']) ?></strong> · <?= e($c['status']) ?> · <?= e(format_datetime($c['created_at'])) ?></p>
        <p>From <?= e($c['reporter_name']) ?> (<?= e($c['reporter_role']) ?>)<?= $c['against_name'] ? ' about ' . e($c['against_name']) : '' ?><?= $c['task_number'] ? ' · task <a href="' . e(url('/admin/tasks/' . $c['task_number'])) . '">' . e($c['task_number']) . '</a> (' . e($c['task_status']) . ')' : '' ?></p>
        <p class="note"><?= nl2br_e($c['description']) ?></p>
        <?php if ($c['resolution']): ?><p class="small">Decision: <?= e($c['resolution']) ?></p><?php endif; ?>
        <?php if ($filter === 'open' && $c['task_status'] !== 'disputed'): ?>
            <form method="post" action="<?= e(url('/admin/complaints/' . $c['id'])) ?>" class="inline-form">
                <?= csrf_field() ?>
                <select name="status"><option value="in_review">In review</option><option value="resolved">Resolved (upheld)</option><option value="rejected">Rejected</option></select>
                <input type="text" name="resolution" placeholder="Decision / reply" size="40">
                <button class="btn btn--sm" type="submit">Save</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
