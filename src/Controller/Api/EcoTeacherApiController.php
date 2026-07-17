<?php

namespace App\Controller\Api;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Repository\EcoCheckpointRepository;
use App\Repository\EcoCourseRepository;
use App\Repository\EcoParcoursRepository;
use App\Security\Voter\EcoParcoursVoter;
use App\Service\EcoLiveTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mobile API for e-CO teachers - authenticated the same way as the rest of moncampus-mobile
 * (JWT via POST /api/login, see config/packages/security.yaml's "api" firewall), just gated by
 * ROLE_ECO on top like the web side (App\Controller\EcoParcoursController). Covers checkpoint
 * geolocation (screens 4b/4c) and the live safety view (4d, sharing App\Service\EcoLiveTrackingService
 * with the web screen 1h so the two never drift apart).
 */
#[IsGranted(new Expression('is_granted("ROLE_ECO") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class EcoTeacherApiController extends AbstractController
{
    // Parcours still needing at least one checkpoint located (screen 4b's entry list) - a fully
    // Ready parcours has nothing left to do here, so it's excluded.
    #[Route(path: '/api/eco/teacher/parcours', name: 'api_eco_teacher_parcours', methods: ['GET'])]
    public function parcoursList(EcoParcoursRepository $repository): JsonResponse
    {
        $parcoursList = array_filter($repository->findForTeacher($this->currentUser()), static fn (EcoParcours $parcours): bool => !$parcours->isReady());

        return $this->json([
            'parcours' => array_map(static fn (EcoParcours $parcours): array => [
                'id' => $parcours->getId(),
                'name' => $parcours->getName(),
                'locatedCount' => $parcours->getLocatedCheckpointCount(),
                'totalCount' => $parcours->getCheckpoints()->count(),
            ], array_values($parcoursList)),
        ]);
    }

    #[Route(path: '/api/eco/teacher/parcours/{id}', name: 'api_eco_teacher_parcours_show', methods: ['GET'])]
    public function parcoursShow(int $id, EcoParcoursRepository $repository): JsonResponse
    {
        $parcours = $this->findParcoursOrNotFound($repository, $id);

        return $this->json([
            'id' => $parcours->getId(),
            'name' => $parcours->getName(),
            'checkpoints' => array_map(fn (EcoCheckpoint $checkpoint): array => $this->formatCheckpoint($checkpoint), $parcours->getCheckpoints()->toArray()),
        ]);
    }

    // Called after the app scans a checkpoint's QR code on the ground (screen 4b -> 4c) - re-
    // scanning an already-located checkpoint simply overwrites its position (EcoCheckpoint::locate()).
    #[Route(path: '/api/eco/teacher/checkpoints/{id}/locate', name: 'api_eco_teacher_checkpoint_locate', methods: ['POST'])]
    public function locate(int $id, Request $request, EntityManagerInterface $entityManager, EcoCheckpointRepository $checkpointRepository): JsonResponse
    {
        $checkpoint = $checkpointRepository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $checkpoint->getParcours());

        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        if (!isset($payload['latitude'], $payload['longitude'])) {
            return $this->json(['error' => 'coordinatesRequired'], 422);
        }

        $checkpoint->locate((float) $payload['latitude'], (float) $payload['longitude'], new \DateTimeImmutable());
        $entityManager->flush();

        $parcours = $checkpoint->getParcours();

        return $this->json([
            'checkpoint' => $this->formatCheckpoint($checkpoint),
            'locatedCount' => $parcours->getLocatedCheckpointCount(),
            'totalCount' => $parcours->getCheckpoints()->count(),
            'parcoursReady' => $parcours->isReady(),
        ]);
    }

    // Entry list for screen 4d - which InProgress courses this teacher can even monitor.
    #[Route(path: '/api/eco/teacher/courses/in-progress', name: 'api_eco_teacher_courses_in_progress', methods: ['GET'])]
    public function coursesInProgress(EcoCourseRepository $repository): JsonResponse
    {
        $courses = $repository->findInProgressForTeacher($this->currentUser());

        return $this->json([
            'courses' => array_map(static fn ($course): array => [
                'id' => $course->getId(),
                'name' => $course->getName(),
                'code' => $course->getCode(),
                'parcoursName' => $course->getParcours()->getName(),
                'runnerCount' => $course->getRunners()->count(),
            ], $courses),
        ]);
    }

    #[Route(path: '/api/eco/teacher/courses/{id}/live', name: 'api_eco_teacher_course_live', methods: ['GET'])]
    public function courseLive(int $id, EcoCourseRepository $repository, EcoLiveTrackingService $liveTracking): JsonResponse
    {
        $course = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $course->getParcours());

        $runners = $liveTracking->sortedBySeverity($course->getRunners()->toArray());

        return $this->json([
            'courseName' => $course->getName(),
            'courseCode' => $course->getCode(),
            'status' => $course->getStatus()->value,
            'runners' => array_map(static fn ($runner): array => $liveTracking->runnerLiveRow($runner), $runners),
        ]);
    }

    private function formatCheckpoint(EcoCheckpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->getId(),
            'name' => $checkpoint->getName(),
            'shortCode' => $checkpoint->getShortCode(),
            'position' => $checkpoint->getPosition(),
            'type' => $checkpoint->getType()->value,
            'toleranceMeters' => $checkpoint->getToleranceMeters(),
            'located' => $checkpoint->isLocated(),
            'latitude' => $checkpoint->getLatitude(),
            'longitude' => $checkpoint->getLongitude(),
            'locatedAt' => $checkpoint->getLocatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function findParcoursOrNotFound(EcoParcoursRepository $repository, int $id): EcoParcours
    {
        $parcours = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        return $parcours;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
