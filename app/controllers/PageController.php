<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;

/** Policy pages (drafts: to be reviewed by a lawyer before launch — see docs/security.md §3). */
final class PageController extends Controller
{
    private const PAGES = [
        'terms'           => 'Terms of Use',
        'privacy'         => 'Privacy Policy',
        'refund-policy'   => 'Cancellation and Refund Policy',
        'helper-agreement'=> 'Helper Agreement',
        'safety'          => 'Safety and Prohibited Items',
    ];

    public function show(Request $request): void
    {
        $slug = trim($request->path, '/');
        if (!isset(self::PAGES[$slug])) {
            $this->notFound();
        }
        $this->render('pages/' . $slug, ['title' => t(self::PAGES[$slug]), 'pages' => self::PAGES]);
    }

    public function offline(Request $request): void
    {
        $this->render('pages/offline', ['title' => t('You are offline')]);
    }
}
