<?php /* Task chat. Vars: $number, $canWrite. Messages load by polling (JS); the form also works without JS. */ ?>
<div class="card chat" id="chat" data-chat-url="<?= e(url('/chat/' . $number)) ?>">
    <h2><?= partial('components/icon', ['name' => 'chat']) ?> <?= e(t('Chat')) ?></h2>
    <div class="chat__log" aria-live="polite" data-empty="<?= e(t('No messages yet.')) ?>">
        <noscript><p class="muted small"><?= e(t('Turn on JavaScript to see messages.')) ?></p></noscript>
    </div>
    <?php if ($canWrite): ?>
        <form method="post" action="<?= e(url('/chat/' . $number)) ?>" class="chat__form">
            <?= csrf_field() ?>
            <input type="text" name="body" maxlength="1000" autocomplete="off" required aria-label="<?= e(t('Message')) ?>" placeholder="<?= e(t('Write a message…')) ?>">
            <button class="btn btn--sm" type="submit"><?= e(t('Send')) ?></button>
        </form>
    <?php endif; ?>
</div>
