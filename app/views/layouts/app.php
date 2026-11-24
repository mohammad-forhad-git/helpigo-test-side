<?php
/*
 * Main layout for the public site, customers and helpers. Mobile-first: on phones the
 * logged-in areas get a bottom tab bar, like a native app.
 */
$siteName = (string) setting('site_name');
$pageTitle = !empty($title) ? $title . ' · ' . $siteName : $siteName . ' — ' . setting('site_tagline');
$user = current_user();
$locale = $user['locale'] ?? (string) App\Core\Session::get('locale', 'en');
$tabs = match ($user['role'] ?? null) {
    'customer' => [
        ['/app', 'home', t('Home')],
        ['/app/tasks', 'list', t('My tasks')],
        ['/app/account', 'user', t('Account')],
    ],
    'worker' => [
        ['/worker', 'home', t('Home')],
        ['/worker/tasks', 'list', t('Tasks')],
        ['/worker/earnings', 'wallet', t('Earnings')],
        ['/worker/profile', 'user', t('Profile')],
    ],
    default => [],
};
// Home tabs (/app, /worker) are active only on that exact page; the others also on their sub-pages.
$currentPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
foreach ($tabs as $i => [$href]) {
    $tabs[$i][3] = in_array($href, ['/app', '/worker'], true) ? $currentPath === url($href) : is_current_path($href);
}
$unread = $user !== null ? App\Services\NotificationService::unreadCount((int) $user['id']) : 0;
?><!doctype html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription ?? t('Hire a verified local helper for medicine, groceries, fresh-market shopping and everyday errands in Dhaka.')) ?>">
    <meta name="theme-color" content="#17325C">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('images/icon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="icon" href="<?= e(asset('images/icon.svg')) ?>" type="image/svg+xml">
</head>
<body class="<?= $tabs ? 'has-tabs' : '' ?>" data-sw="<?= e(url('sw.js')) ?>">
<a class="skip-link" href="#main"><?= e(t('Skip to content')) ?></a>
<header class="topbar">
    <div class="container topbar__inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <img src="<?= e(asset('images/icon.svg')) ?>" alt="" width="30" height="30">
            <span><?= e($siteName) ?></span>
        </a>
        <nav class="topbar__nav" aria-label="<?= e(t('Main')) ?>">
            <?php if ($user === null): ?>
                <form method="post" action="<?= e(url('/language')) ?>" class="lang-switch">
                    <?= csrf_field() ?>
                    <button type="submit" name="locale" value="<?= $locale === 'bn' ? 'en' : 'bn' ?>" class="link-button"><?= $locale === 'bn' ? 'English' : 'বাংলা' ?></button>
                </form>
                <a href="<?= e(url('/register?as=worker')) ?>" class="link-quiet hide-sm"><?= e(t('Become a helper')) ?></a>
                <a href="<?= e(url('/login')) ?>" class="btn btn--ghost btn--sm"><?= e(t('Log in')) ?></a>
            <?php else: ?>
                <?php foreach ($tabs as [$href, $icon, $label, $active]): ?>
                    <a href="<?= e(url($href)) ?>" class="topbar__link hide-sm<?= $active ? ' is-active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
                <a href="<?= e(url('/notifications')) ?>" class="bell" aria-label="<?= e(t('Notifications')) ?>" data-unread-url="<?= e(url('/notifications/unread')) ?>">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                    <span class="bell__count" <?= $unread === 0 ? 'hidden' : '' ?>><?= $unread > 9 ? '9+' : $unread ?></span>
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main id="main">
    <?= partial('components/flash') ?>
    <?= $content ?>
</main>
<?php if ($tabs): ?>
    <nav class="tabbar" aria-label="<?= e(t('App')) ?>">
        <?php foreach ($tabs as [$href, $icon, $label, $active]): ?>
            <a href="<?= e(url($href)) ?>" class="tabbar__item<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                <?= partial('components/icon', ['name' => $icon]) ?>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php else: ?>
    <footer class="footer">
        <div class="container footer__inner">
            <p><strong><?= e($siteName) ?></strong> · <?= e(setting('site_tagline')) ?></p>
            <p class="muted"><?= e(t('Service hours')) ?>: <?= e(setting('service_hours')) ?><?= setting('support_phone') ? ' · ' . e(t('Support')) . ': ' . e(setting('support_phone')) : '' ?></p>
            <p class="footer__links">
                <a href="<?= e(url('/terms')) ?>"><?= e(t('Terms of Use')) ?></a>
                <a href="<?= e(url('/privacy')) ?>"><?= e(t('Privacy Policy')) ?></a>
                <a href="<?= e(url('/refund-policy')) ?>"><?= e(t('Cancellation and Refund Policy')) ?></a>
                <a href="<?= e(url('/safety')) ?>"><?= e(t('Safety and Prohibited Items')) ?></a>
                <a href="<?= e(url('/helper-agreement')) ?>"><?= e(t('Helper Agreement')) ?></a>
            </p>
        </div>
    </footer>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
