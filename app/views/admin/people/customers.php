<h1 class="page__title">Customers</h1>
<form method="get" action="<?= e(url('/admin/customers')) ?>" class="inline-form filter-bar">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name or phone">
    <button class="btn btn--sm" type="submit">Search</button>
</form>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Name</th><th>Phone</th><th>Status</th><th class="num">Tasks</th><th class="num">Balance</th><th>Joined</th></tr></thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
        <tr>
            <td><a href="<?= e(url('/admin/customers/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td>
            <td><?= e($c['phone']) ?> <?= $c['phone_verified_at'] ? '✓' : '' ?></td>
            <td><?= e($c['status']) ?></td>
            <td class="num"><?= (int) $c['tasks'] ?></td>
            <td class="num<?= (float) $c['balance'] < 0 ? ' danger-text' : '' ?>"><?= e(money_db($c['balance'])) ?></td>
            <td><?= e(format_date($c['created_at'])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($customers === []): ?><tr><td colspan="6" class="muted">None.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
