<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The retired Campus web app (design/validated/quiz-tournament.md, §13). /campus-app/ served the
 * moncampus-mobile Flutter app compiled for the browser; the site itself is now the installable
 * app (« Formatool », App\Controller\InstallController), and two PWAs remain: Formatool and e-CO.
 *
 * Every old address - the app's start page, a bookmark, a `?login=` link from an old mail - lands
 * on the site's home, permanently. Caddy never sends here the one file left in public/campus-app/,
 * the kill-switch service worker (campus_sw.js), which a browser fetches to update the old one and
 * must get as a script, never as a redirect. Chrome does not check the old worker for an update on
 * a navigation this redirect takes out of its scope, so the site also unregisters it and deletes
 * its caches from the page the redirect lands on (assets/web_app/retired_campus_app.js).
 *
 * No account and no feature: somebody arriving from an old icon may well be signed out, and the
 * home then sends them to the login screen like any other visit.
 */
class CampusAppRetiredController extends AbstractController
{
    #[Route(path: '/campus-app/{path}', name: 'app_campus_app_retired', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'HEAD'])]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('app_home', status: Response::HTTP_MOVED_PERMANENTLY);
    }
}
