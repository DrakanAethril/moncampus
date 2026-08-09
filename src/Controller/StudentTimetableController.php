<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ProgramRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Target of the student navbar's "Emploi du temps" tab (design_handoff_dashboards §3) - a stable
 * URL that doesn't need the Program id baked into the navbar. Resolves the student's active
 * Program and forwards to its timetable page; with two formations (etu-e) the most recent one
 * wins, the other stays reachable from the dashboard's per-formation links.
 */
class StudentTimetableController extends AbstractController
{
    #[Route(path: '/my/timetable', name: 'app_my_timetable')]
    #[IsGranted('ROLE_STUDENT')]
    public function __invoke(ProgramRepository $programRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        foreach ($programRepository->findAllActiveForStudent($user) as $program) {
            if ($program->isTimetableManagementEnabled()) {
                return $this->redirectToRoute('app_program_timetable', ['id' => $program->getId()]);
            }
        }

        throw $this->createNotFoundException();
    }
}
