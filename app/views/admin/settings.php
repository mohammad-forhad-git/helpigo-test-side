<h1 class="page__title">Settings</h1>
<form method="post" action="<?= e(url('/admin/settings')) ?>" class="card form">
    <?= csrf_field() ?>
    <h2>Fees, limits and contacts</h2>
    <div class="settings-grid">
        <?php foreach ($fields as $key => [$label, $kind, $help]): ?>
            <label class="field">
                <span><?= e($label) ?></span>
                <input type="text" name="<?= e($key) ?>" value="<?= e((string) setting($key, '')) ?>" <?= $kind === 'money' || $kind === 'rate' ? 'inputmode="decimal"' : '' ?>>
                <?php if ($help !== ''): ?><small class="muted"><?= e($help) ?></small><?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Save settings</button>
</form>

<div class="card" id="services">
    <h2>Services</h2>
    <?php foreach ($categories as $c): ?>
        <form method="post" action="<?= e(url('/admin/categories/' . $c['id'])) ?>" class="inline-form category-row">
            <?= csrf_field() ?>
            <input type="text" name="name" value="<?= e($c['name']) ?>" size="18" aria-label="Name">
            <label>Fee ৳ <input type="text" name="base_fee" value="<?= e((string) (float) $c['base_fee']) ?>" size="5"></label>
            <label>Included items <input type="text" name="included_items" value="<?= (int) $c['included_items'] ?>" size="3"></label>
            <label>Extra item ৳ <input type="text" name="per_item_fee" value="<?= e((string) (float) $c['per_item_fee']) ?>" size="4"></label>
            <input type="text" name="description" value="<?= e($c['description'] ?? '') ?>" size="30" aria-label="Description">
            <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int) $c['is_active'] === 1 ? 'checked' : '' ?>><span>Active</span></label>
            <span class="muted small"><?= e($c['pricing_mode']) ?></span>
            <button class="btn btn--sm" type="submit">Save</button>
        </form>
    <?php endforeach; ?>
</div>

<div class="card" id="zones">
    <h2>Zones</h2>
    <p class="small muted">Open new zones only when enough helpers serve them. Zones with tasks cannot be deleted; deactivate them instead.</p>
    <?php foreach ($zones as $z): ?>
        <form method="post" action="<?= e(url('/admin/zones')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
            <input type="text" name="name" value="<?= e($z['name']) ?>" size="16" aria-label="Name">
            <input type="text" name="name_bn" value="<?= e($z['name_bn'] ?? '') ?>" size="14" aria-label="Bangla name">
            <label>Order <input type="text" name="sort_order" value="<?= (int) $z['sort_order'] ?>" size="2"></label>
            <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int) $z['is_active'] === 1 ? 'checked' : '' ?>><span>Active</span></label>
            <span class="muted small"><?= (int) $z['used'] ?> tasks</span>
            <button class="btn btn--sm" type="submit">Save</button>
        </form>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('/admin/zones')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="text" name="name" placeholder="New zone name" size="16" required>
        <input type="text" name="name_bn" placeholder="বাংলা নাম" size="14">
        <input type="hidden" name="sort_order" value="<?= count($zones) + 1 ?>">
        <label class="check"><input type="checkbox" name="is_active" value="1"><span>Active</span></label>
        <button class="btn btn--sm btn--ghost" type="submit">Add zone</button>
    </form>
</div>
