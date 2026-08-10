<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TechnicalProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Description technique" - what this application is made of, written for the students who study
 * in the school it runs for.
 *
 * The school prepares the BTS SIO, so the page is laid out the way that syllabus is: a common trunk,
 * then what concerns the SLAM option (development) and what concerns SISR (infrastructure and
 * networks). It describes the application as it is - no roadmap, no "what could be improved": a
 * student reading it must be able to open the source and find exactly what is claimed.
 *
 * Every figure on the page is measured, not typed (App\Service\TechnicalProfile). That is
 * deliberate and pedagogical in itself: the volumetry of a codebase is a fact about a commit, not a
 * sentence in a template.
 *
 * Open to every authenticated account, like "À propos" and "Changelog", and linked from the profile
 * menu just above "À propos".
 */
#[IsGranted('ROLE_USER')]
class TechnicalController extends AbstractController
{
    #[Route(path: '/technical', name: 'app_technical', methods: ['GET'])]
    public function index(TechnicalProfile $profile): Response
    {
        return $this->render('technical/index.html.twig', [
            'figures' => $profile->figures(),
        ]);
    }
}
