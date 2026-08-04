<?php

namespace App\Controller;

use App\Entity\JobApplication;
use App\Entity\User;
use App\Repository\JobApplicationRepository;
use App\Repository\JobSearchRepository;
use App\Service\JobApplicationSummaryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mes démarches » — la vue étudiant de ses propres candidatures
 * (design_handoff_stage_alternance, écran 2b).
 *
 * La créa est délibérément dépouillée, et le README l'énonce comme une contrainte : pas de
 * bannière, pas de bloc « à faire » ni de relances, pas d'objectifs, pas de colonne de droite,
 * pas de « Déclarer une démarche ». Une liste pleine largeur, groupée par entreprise, et des
 * lignes purement factuelles. Ne pas y rajouter d'accompagnement : celui-ci vit côté enseignant
 * (écran 2a), pas ici.
 */
class MyJobApplicationController extends AbstractController
{
    /** Les filtres de la créa. « En attente » = aucune réponse reçue, pas un jugement sur l'issue. */
    private const array FILTERS = ['all', 'pending', 'answered'];

    #[Route(path: '/my/applications', name: 'app_my_job_applications', methods: ['GET'])]
    #[IsGranted('ROLE_STUDENT')]
    public function __invoke(
        Request $request,
        JobApplicationRepository $applicationRepository,
        JobSearchRepository $searchRepository,
        JobApplicationSummaryBuilder $summaryBuilder,
    ): Response {
        /** @var User $student */
        $student = $this->getUser();

        $filter = (string) $request->query->get('filter', 'all');

        if (!\in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        $rows = [];

        foreach ($applicationRepository->findForStudent($student) as $application) {
            $summary = $summaryBuilder->summarize($application);

            if (!$this->matchesFilter($summary, $filter)) {
                continue;
            }

            $rows[] = ['application' => $application, 'summary' => $summary];
        }

        return $this->render('job_application/my_applications.html.twig', [
            'rows' => $rows,
            'filter' => $filter,
            'filters' => self::FILTERS,
            // Une recherche close laisse la boîte consultable mais désactive l'envoi (écran 1a) :
            // l'écran doit donc le dire, sans rien masquer.
            'searchClosed' => $searchRepository->isClosedFor($student),
        ]);
    }

    /** @param array{replyAt: ?\DateTimeImmutable} $summary */
    private function matchesFilter(array $summary, string $filter): bool
    {
        return match ($filter) {
            'pending' => null === $summary['replyAt'],
            'answered' => null !== $summary['replyAt'],
            default => true,
        };
    }
}
