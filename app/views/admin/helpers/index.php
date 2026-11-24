<h1 class="page__title">Helpers</h1>
<nav class="segmented">
    <?php foreach (['' => 'All', 'pending' => 'To review', 'approved' => 'Approved', 'incomplete' => 'Incomplete', 'rejected' => 'Rejected', 'suspended' => 'Suspended'] as $k => $label): ?>
        <a href="<?= e(url('/admin/helpers', $k !== '' ? ['status' => $k] : [])) ?>" class="<?= $status === $k ? 'is-active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Name</th><th>Phone</th><th>Status</th><th>Online</th><th class="num">Done</th><th class="num">Rating</th><th class="num">Limit</th><th class="num">Balance</th><th>Joined</th></tr></thead>
    <tbody>
    <?php foreach ($helpers as $h): ?>
        <tr>
            <td><a href="<?= e(url('/admin/helpers/' . $h['user_id'])) ?>"><?= e($h['name']) ?></a><?= $h['gender'] === 'female' ? ' <small class="muted">F</small>' : '' ?></td>
            <td><?= e($h['phone']) ?></td>
            <td><span class="badge badge--h-<?= e($h['status']) ?>"><?= e($h['status']) ?></span></td>
            <td><?= (int) $h['is_online'] === 1 ? '●' : '' ?></td>
            <td class="num"><?= (int) $h['completed_count'] ?></td>
            <td class="num"><?= (int) $h['rating_count'] > 0 ? e(number_format((float) $h['rating_avg'], 2)) : '–' ?></td>
            <td class="num"><?= e(money_db($h['float_limit'], false)) ?></td>
            <td class="num<?= (float) $h['balance'] < 0 ? ' danger-text' : '' ?>"><?= e(money_db($h['balance'])) ?></td>
            <td><?= e(format_date($h['joined_at'])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($helpers === []): ?><tr><td colspan="9" class="muted">No helpers.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
