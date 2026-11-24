<section class="hero">
    <div class="container hero__inner">
        <div class="hero__text">
            <p class="eyebrow"><?= e(t('Help · Hire · Get it done')) ?></p>
            <h1><?= e(t('Your errands, done by a verified helper nearby.')) ?></h1>
            <p class="lead"><?= e(t('Medicine, groceries, fresh-market shopping, pick-ups and custom tasks. Set your budget, approve any change, pay when it arrives.')) ?></p>
            <div class="hero__actions">
                <a class="btn btn--lg" href="<?= e(url('/register')) ?>"><?= e(t('Post a task')) ?></a>
                <a class="btn btn--lg btn--ghost" href="<?= e(url('/register?as=worker')) ?>"><?= e(t('Earn as a helper')) ?></a>
            </div>
            <?php if ($zones): ?>
                <p class="muted small"><?= e(t('Now serving')) ?>: <?= e(implode(', ', array_column($zones, 'name'))) ?></p>
            <?php endif; ?>
        </div>
        <ol class="hero__steps" aria-label="<?= e(t('How it works')) ?>">
            <li><span class="step-num">1</span><div><strong><?= e(t('Describe the task')) ?></strong><p><?= e(t('List items or snap a photo of your list or prescription. Set a budget.')) ?></p></div></li>
            <li><span class="step-num">2</span><div><strong><?= e(t('A verified helper accepts')) ?></strong><p><?= e(t('ID-checked helpers in your area. See their rating before they start.')) ?></p></div></li>
            <li><span class="step-num">3</span><div><strong><?= e(t('Track and approve')) ?></strong><p><?= e(t('Receipt photo, price changes need your OK, chat with your helper.')) ?></p></div></li>
            <li><span class="step-num">4</span><div><strong><?= e(t('Receive with your code')) ?></strong><p><?= e(t('Give your 4-digit delivery code only when you have your items.')) ?></p></div></li>
        </ol>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2><?= e(t('What can we help with?')) ?></h2>
        <div class="cat-grid">
            <?php foreach ($categories as $c): ?>
                <a class="cat-card" href="<?= e(url('/register')) ?>">
                    <span class="cat-card__icon"><?= partial('components/icon', ['name' => $c['icon']]) ?></span>
                    <span class="cat-card__name"><?= e(local_name($c)) ?></span>
                    <span class="cat-card__desc"><?= e($c['description']) ?></span>
                    <span class="cat-card__price">
                        <?= $c['pricing_mode'] === 'offer' ? e(t('From')) . ' ' : '' ?><?= e(money_db($c['base_fee'], false)) ?> <?= e(t('helper fee')) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section section--alt">
    <div class="container trust-grid">
        <div><?= partial('components/icon', ['name' => 'shield']) ?><h3><?= e(t('Verified helpers')) ?></h3><p><?= e(t('National ID and photo checked, reference contact, ratings after every task.')) ?></p></div>
        <div><?= partial('components/icon', ['name' => 'receipt']) ?><h3><?= e(t('No surprise costs')) ?></h3><p><?= e(t('Fees shown before you book. Items charged at the receipt price, never above your budget without your approval.')) ?></p></div>
        <div><?= partial('components/icon', ['name' => 'key']) ?><h3><?= e(t('Delivery code')) ?></h3><p><?= e(t('The task only completes when you give the helper your code.')) ?></p></div>
        <div><?= partial('components/icon', ['name' => 'user']) ?><h3><?= e(t('Book for family')) ?></h3><p><?= e(t('Living away? Send a helper to your parents and follow every step.')) ?></p></div>
    </div>
</section>
