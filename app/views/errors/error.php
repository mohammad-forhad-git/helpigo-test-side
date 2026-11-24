<section class="error-page">
    <p class="eyebrow">Error <?= (int) $status ?></p>
    <h1><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p><a class="btn" href="<?= e(url('/')) ?>">Go to home</a></p>
    <?php if (!empty($debug)): /* local development only (APP_DEBUG=true) */ ?>
        <pre class="debug"><?= e(get_class($debug) . ': ' . $debug->getMessage() . "\n" . $debug->getFile() . ':' . $debug->getLine() . "\n\n" . $debug->getTraceAsString()) ?></pre>
    <?php endif; ?>
</section>
