<nav class="policy-nav" aria-label="<?= e(t('Policies')) ?>">
    <?php foreach ($pages as $slug => $label): ?>
        <a href="<?= e(url('/' . $slug)) ?>" class="<?= is_current_path('/' . $slug) ? 'is-active' : '' ?>"><?= e(t($label)) ?></a>
    <?php endforeach; ?>
</nav>
<p class="note small"><?= e(t('Draft for the pilot. Last updated: :date. Questions: :phone', ['date' => '16 November 2026', 'phone' => setting('support_phone') ?: setting('support_email') ?: 'Helpigo support'])) ?></p>
