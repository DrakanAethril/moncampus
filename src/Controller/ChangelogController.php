<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Cadence;
use App\Service\Changelog;
use App\Service\ChangelogStats;
use App\Service\MeasuredProfile;
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
    public function index(
        Changelog $changelog,
        ChangelogStats $stats,
        Cadence $cadence,
        MeasuredProfile $measured,
    ): Response {
        $releases = $changelog->releases();

        // The span the rhythm figures are computed over: from the first release ever to today. Both
        // ends move on their own, which is what keeps the sidebar true without anyone editing it.
        // Releases come newest-first, so the oldest is the last one.
        $first = $releases[array_key_last($releases)] ?? null;
        $days = null !== $first ? (int) $first->date->diff(new \DateTimeImmutable('today'))->days : 0;

        return $this->render('changelog/index.html.twig', [
            'releases' => $releases,
            'counts' => Changelog::entryCounts($releases),
            'stats' => $stats->of($releases),
            'since' => $first,
            'days' => $days,
            'commits' => $measured->commits(),
            'commitCadence' => $cadence->describe($measured->commits(), $days),
            'releaseCadence' => $cadence->describe(count($releases), $days),
        ]);
    }
}
