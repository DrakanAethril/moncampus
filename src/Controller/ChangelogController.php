<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Changelog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Changelog" - what shipped to production, release by release.
 *
 * Open to every authenticated account, like "À propos": there is nothing here that is anyone's
 * business in particular, and a member of staff wondering why a screen moved this morning should
 * not have to ask. The link sits in the profile menu between "Aide" and "À propos".
 *
 * The English name is deliberate and is the one exception this app makes to its own
 * French-display-text rule: "Changelog" is what the users themselves call it, and the French
 * candidates ("Journal des modifications", "Nouveautés") each say less than the borrowed word.
 */
#[IsGranted('ROLE_USER')]
class ChangelogController extends AbstractController
{
    #[Route(path: '/changelog', name: 'app_changelog', methods: ['GET'])]
    public function index(Changelog $changelog): Response
    {
        return $this->render('changelog/index.html.twig', [
            'releases' => $changelog->releases(),
        ]);
    }
}
