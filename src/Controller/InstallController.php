<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Installer Formatool » (design/validated/quiz-tournament.md, §13): how to put the site - Formatool, under that name since 2026-11-11 (« beaup-app » before) - on the
 * home screen of a phone, a tablet or a computer, where it opens full screen with no address bar -
 * which is what the live quiz and the tournament's duel want (no accidental back gesture, no
 * scrolled-away bar).
 *
 * The page itself decides nothing on the server: which card applies (the browser's install offer on
 * Android and on a desktop, the Share-menu steps on an iPhone or an iPad), and « déjà installée »
 * when it runs standalone, are read in the browser by web_app_install_controller.js. The offer is
 * the `beforeinstallprompt` event, captured on every page by assets/web_app/install_prompt.js and
 * kept across Turbo visits, so that it is still there when somebody reaches this screen.
 *
 * **No service worker.** Chrome and Edge stopped asking for one (a fetch handler, an offline page)
 * before they offer installation; measured on Chromium 156 with an empty registration list:
 * no installability error, and `beforeinstallprompt` fires. Safari never needed one. And there is
 * nothing to cache: Turbo and the live screens would gain nothing offline. No notification either -
 * the installed app asks for no permission at all (§1, decision 7).
 *
 * Open to every authenticated account and outside the feature catalogue, like « À propos »: it is
 * how somebody uses their own account, not a feature an establishment runs.
 */
#[IsGranted('ROLE_USER')]
class InstallController extends AbstractController
{
    #[Route(path: '/install', name: 'app_install', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('install/index.html.twig');
    }
}
