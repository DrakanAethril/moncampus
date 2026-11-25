<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\EcoCourse;
use App\Enum\Feature;
use App\Repository\EcoCourseRepository;
use App\Security\Voter\EcoParcoursVoter;
use App\Service\Eco\EcoCheckpointReports;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Flags missing from the terrain, on the live screen (screen 1h): the block that lists what the
 * runners reported (« Balise introuvable ») and lets the teacher neutralise a flag for the whole
 * race - nobody has to find it any more, a race run in order passes over it and a run is complete
 * without it - or restore it. Same access as the rest of the course's screens
 * (EcoCourseController::findCourseOrNotFound()).
 */
#[IsGranted(new Expression('is_granted("ROLE_ECO") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Eco)]
class EcoCheckpointNeutralizationController extends AbstractController
{
    /** The block alone, asked again by the live screen when the reports' signature moves. */
    #[Route(path: '/eco/courses/{id}/live/reports', name: 'app_eco_course_live_reports', methods: ['GET'])]
    public function block(int $id, EcoCourseRepository $repository, EcoCheckpointReports $reports): Response
    {
        $course = $this->course($repository, $id);

        return $this->render('eco/_live_reports.html.twig', [
            'course' => $course,
            'reports' => $reports->forCourse($course),
            'neutralizable' => $reports->neutralizable($course),
        ]);
    }

    #[Route(path: '/eco/courses/{id}/checkpoints/neutralize', name: 'app_eco_course_checkpoint_neutralize', methods: ['POST'])]
    public function neutralize(int $id, Request $request, EcoCourseRepository $repository, EntityManagerInterface $entityManager): Response
    {
        $course = $this->course($repository, $id);
        if (!$this->isCsrfTokenValid('eco_course_checkpoint_neutralize', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $checkpointId = $request->request->getString('checkpoint');
        foreach ($course->getRaceCheckpoints() as $checkpoint) {
            if ((string) $checkpoint->getId() !== $checkpointId) {
                continue;
            }

            if ('restore' === $request->request->getString('action')) {
                $course->restore($checkpoint);
            } else {
                $course->neutralize($checkpoint);
            }
            $entityManager->flush();

            break;
        }

        return $this->redirectToRoute('app_eco_course_live', ['id' => $course->getId()]);
    }

    private function course(EcoCourseRepository $repository, int $id): EcoCourse
    {
        $course = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $course->getParcours());

        return $course;
    }
}
