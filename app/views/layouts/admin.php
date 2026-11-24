<?php
$user = current_user();
$nav = [
    '/admin' => 'Dashboard', '/admin/tasks' => 'Tasks', '/admin/helpers' => 'Helpers', '/admin/payments' => 'Payments',
    '/admin/money/helpers' => 'Helper money', '/admin/complaints' => 'Complaints', '/admin/customers' => 'Customers',
    '/admin/reports' => 'Reports', '/admin/settings' => 'Settings',
];
$badges = [
    '/admin/helpers'    => (int) App\Core\Database::value("SELECT COUNT(*) FROM worker_profiles WHERE status = 'pending'"),
    '/admin/payments'   => (int) App\Core\Database::value("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
    '/admin/complaints' => (int) App\Core\Database::value("SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_review')"),
    '/admin/money/helpers' => (int) App\Core\Database::value("SELECT COUNT(*) FROM payout_requests WHERE status = 'pending'"),
];
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? 'Admin') . ' · ' . setting('site_name') . ' admin') ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="icon" href="<?= e(asset('images/icon.svg')) ?>" type="image/svg+xml">
</head>
<body class="admin">
<header class="topbar topbar--admin">
    <div class="container container--wide topbar__inner">
        <a class="brand" href="<?= e(url('/admin')) ?>">
            <img src="<?= e(asset('images/icon.svg')) ?>" alt="" width="28" height="28">
            <span><?= e(setting('site_name')) ?> <small>admin</small></span>
        </a>
        <nav class="topbar__nav" aria-label="Account">
            <a class="topbar__link" href="<?= e(url('/notifications')) ?>">Notifications</a>
            <a class="topbar__link hide-sm" href="<?= e(url('/settings')) ?>"><?= e($user['name'] ?? '') ?></a>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" type="submit">Log out</button>
            </form>
        </nav>
    </div>
    <nav class="admin-nav" aria-label="Admin">
        <div class="container container--wide admin-nav__inner">
            <?php foreach ($nav as $href => $label):
                $active = $href === '/admin' ? $path === url('/admin') : str_starts_with($path, url($href)); ?>
                <a href="<?= e(url($href)) ?>" class="<?= $active ? 'is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if (($badges[$href] ?? 0) > 0): ?> <span class="count"><?= (int) $badges[$href] ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>
<main id="main" class="container container--wide page">
    <?= partial('components/flash') ?>
    <?= $content ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
