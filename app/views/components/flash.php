<?php
use App\Core\Session;

$messages = [
    'success' => Session::getFlash('success'),
    'error'   => Session::getFlash('error'),
    'info'    => Session::getFlash('info'),
];
$messages = array_filter($messages, static fn ($m) => is_string($m) && $m !== '');
if ($messages === []) {
    return;
}
?>
<div class="flash-wrap container">
    <?php foreach ($messages as $type => $message): ?>
        <div class="flash flash--<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($message) ?></div>
    <?php endforeach; ?>
</div>
