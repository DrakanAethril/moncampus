<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Service\Jobboard\JobboardHistory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Configuration > Jobboard > Historique » - what has been deposited, and by whom.
 *
 * Read-only, and deliberately so: nothing on this screen can be corrected, because the figures it
 * prints were counted at the moment the offers were filed and are the only record of it. The one
 * question it answers is « la veille a-t-elle tourné, et qu'a-t-elle rapporté » - and, since the
 * list of sites was opened, a second one: « qu'a-t-elle décidé toute seule ».
 *
 * Administrators only and without a `#[RequiresFeature]`, like the two tabs beside it: no setting
 * made here may close the screen the settings are made on.
 */
#[IsGranted('ROLE_ADMIN')]
class JobboardHistoryController extends AbstractController
{
    #[Route(path: '/settings/jobboard/history', name: 'app_settings_jobboard_history', methods: ['GET'])]
    public function index(JobboardHistory $history): Response
    {
        return $this->render('settings/jobboard_history.html.twig', [
            'rows' => $history->rows(),
            // Handed to the template so the « only the last N » line cannot drift from the number
            // actually applied.
            'limit' => JobboardHistory::DEFAULT_LIMIT,
            // The second list of this screen: what the source resolution decided by itself. It
            // belongs here rather than on the sources tab because it is dated and attributable -
            // it says which pass attached a domain, which the sources tab cannot.
            'learnings' => $history->learnings(),
        ]);
    }
}
