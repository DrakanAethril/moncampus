<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Attribute\RequiresFeature;
use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCourseMode;
use App\Enum\EcoMapVisibility;
use App\Enum\Feature;
use App\Form\EcoCourseType;
use App\Repository\EcoCheckpointRepository;
use App\Repository\EcoCourseRepository;
use App\Repository\EcoParcoursRepository;
use App\Security\Voter\EcoParcoursVoter;
use App\Service\Eco\EcoCheckpointTerrainReader;
use App\Service\Eco\EcoToleranceAdvisor;
use App\Service\EcoCourseCodeGenerator;
use App\Service\EcoLiveTrackingService;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mobile API for e-CO teachers - authenticated the same way as the rest of moncampus-mobile
 * (JWT via POST /api/login, see config/packages/security.yaml's "api" firewall), just gated by
 * ROLE_ECO on top like the web side (App\Controller\EcoParcoursController). Covers checkpoint
 * geolocation (screens 4b/4c), the live safety view (4d, sharing App\Service\EcoLiveTrackingService
 * with the web screen 1h so the two never drift apart), and the courses of a Ready parcours -
 * create, start, close - with the same form and the same EcoCourse::start()/close() as screen 1g.
 */
#[IsGranted(new Expression('is_granted("ROLE_ECO") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Eco)]
class EcoTeacherApiController extends AbstractController
{
    /**
     * Seconds the IGN is given to read the ground under a flag just located: the teacher is
     * standing in the wood waiting for the confirmation screen. Past it the flag is saved all the
     * same, unread, and app:eco:read-terrain reads it with the parcours analysis.
     */
    private const float TERRAIN_READ_SECONDS = 4.0;

    public function __construct(
        private readonly EcoToleranceAdvisor $toleranceAdvisor,
        private readonly TranslatorInterface $translator,
    ) {
    }

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

    // The other half of the teacher's parcours: every flag located, so courses can be run on it.
    #[Route(path: '/api/eco/teacher/parcours/ready', name: 'api_eco_teacher_parcours_ready', methods: ['GET'])]
    public function readyParcoursList(EcoParcoursRepository $repository): JsonResponse
    {
        $parcoursList = array_filter($repository->findForTeacher($this->currentUser()), static fn (EcoParcours $parcours): bool => $parcours->isReady());

        return $this->json([
            'parcours' => array_map(static function (EcoParcours $parcours): array {
                $counts = ['prepared' => 0, 'in_progress' => 0, 'closed' => 0];
                foreach ($parcours->getCourses() as $course) {
                    ++$counts[$course->getStatus()->value];
                }

                return [
                    'id' => $parcours->getId(),
                    'name' => $parcours->getName(),
                    'checkpointCount' => $parcours->getCheckpoints()->count(),
                    'preparedCount' => $counts['prepared'],
                    'inProgressCount' => $counts['in_progress'],
                    'closedCount' => $counts['closed'],
                ];
            }, array_values($parcoursList)),
        ]);
    }

    #[Route(path: '/api/eco/teacher/parcours/{id}', name: 'api_eco_teacher_parcours_show', requirements: ['id' => '\d+'], methods: ['GET'])]
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
    public function locate(int $id, Request $request, EntityManagerInterface $entityManager, EcoCheckpointRepository $checkpointRepository, EcoCheckpointTerrainReader $terrainReader): JsonResponse
    {
        $checkpoint = $checkpointRepository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $checkpoint->getParcours());

        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        if (!isset($payload['latitude'], $payload['longitude'])) {
            return $this->json(['error' => 'coordinatesRequired'], 422);
        }

        $checkpoint->locate((float) $payload['latitude'], (float) $payload['longitude'], new \DateTimeImmutable());
        // Saved first: a slow IGN must never cost the teacher the position they walked to.
        $entityManager->flush();

        $terrainReader->read($checkpoint, self::TERRAIN_READ_SECONDS);

        $parcours = $checkpoint->getParcours();
        // The last flag located, or one moved on a parcours already read: the analysis is (re)done.
        // A re-scan on the same spot leaves the analysis current and asks for nothing.
        if (($parcours->isReady() || null !== $parcours->getTerrainAnalysis()) && !$parcours->hasCurrentTerrainAnalysis()) {
            $parcours->requestTerrainAnalysis(new \DateTimeImmutable());
        }
        $entityManager->flush();

        return $this->json([
            'checkpoint' => $this->formatCheckpoint($checkpoint),
            'locatedCount' => $parcours->getLocatedCheckpointCount(),
            'totalCount' => $parcours->getCheckpoints()->count(),
            'parcoursReady' => $parcours->isReady(),
        ]);
    }

    // Screen 1g on the phone: a Ready parcours' courses, and what the creation form offers. The
    // choices are worded here, from the same translation keys as the web form, so the app never
    // carries its own copy of what « Ordre libre » means.
    #[Route(path: '/api/eco/teacher/parcours/{id}/courses', name: 'api_eco_teacher_parcours_courses', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function parcoursCourses(int $id, EcoParcoursRepository $parcoursRepository, EcoCourseRepository $courseRepository): JsonResponse
    {
        $parcours = $this->findReadyParcoursOrNotFound($parcoursRepository, $id);

        return $this->json([
            'parcours' => ['id' => $parcours->getId(), 'name' => $parcours->getName()],
            'courses' => array_map(fn (EcoCourse $course): array => $this->formatCourse($course), $courseRepository->findForParcours($parcours)),
            'options' => [
                'modes' => array_map(fn (EcoCourseMode $mode): array => [
                    'value' => $mode->value,
                    'label' => $this->translator->trans($mode->labelKey()),
                    'description' => $this->translator->trans($mode->descriptionKey()),
                    // Imposed order is ranked on time: there is no allowance to run out of.
                    'timeLimited' => EcoCourseMode::ImposedOrder !== $mode,
                ], EcoCourseMode::cases()),
                'mapVisibilities' => array_map(fn (EcoMapVisibility $visibility): array => [
                    'value' => $visibility->value,
                    'label' => $this->translator->trans($visibility->labelKey()),
                ], EcoMapVisibility::cases()),
            ],
        ]);
    }

    /**
     * « Nouvelle course », through the web's own EcoCourseType so the rules (a name, a positive
     * allowance, a known mode) are written once. The join code is drawn here, at creation: the
     * phone shows it on the course card, which is where the teacher reads it out from.
     */
    #[Route(path: '/api/eco/teacher/parcours/{id}/courses', name: 'api_eco_teacher_course_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createCourse(int $id, Request $request, EntityManagerInterface $entityManager, EcoParcoursRepository $parcoursRepository, EcoCourseCodeGenerator $codeGenerator): JsonResponse
    {
        $parcours = $this->findReadyParcoursOrNotFound($parcoursRepository, $id);
        $payload = JsonRequestPayload::fromRequest($request);

        $course = new EcoCourse($parcours, $this->currentUser());
        // An unknown value is refused by the form; an empty one would reach setMode() as null, so
        // it falls back to the entity's default like an absent one.
        $mode = '' !== $payload->string('mode') ? $payload->string('mode') : $course->getMode()->value;
        $mapVisibility = '' !== $payload->string('mapVisibility') ? $payload->string('mapVisibility') : $course->getMapVisibility()->value;
        $timeLimit = $payload->int('timeLimitMinutes');

        $form = $this->createForm(EcoCourseType::class, $course, ['csrf_protection' => false]);
        $form->submit([
            'name' => trim($payload->string('name')),
            'mode' => $mode,
            'timeLimitMinutes' => (null !== $timeLimit && EcoCourseMode::ImposedOrder->value !== $mode) ? (string) $timeLimit : null,
            'mapVisibility' => $mapVisibility,
            // A checkbox reads any submitted value as ticked: null is what unticks it.
            'teamsEnabled' => $payload->bool('teamsEnabled', $course->isTeamsEnabled()) ? '1' : null,
            'safetyAlertsEnabled' => $payload->bool('safetyAlertsEnabled', $course->isSafetyAlertsEnabled()) ? '1' : null,
        ]);

        if (!$form->isValid()) {
            $fields = [];
            foreach ($form->getErrors(true) as $error) {
                $origin = $error->getOrigin();
                $fields[null !== $origin ? $origin->getName() : 'form'] ??= $error->getMessage();
            }

            return $this->json(['error' => 'invalidCourse', 'fields' => $fields], 422);
        }

        $course->setCode($codeGenerator->generate());
        $entityManager->persist($course);
        $entityManager->flush();

        return $this->json(['course' => $this->formatCourse($course)], 201);
    }

    #[Route(path: '/api/eco/teacher/courses/{id}/start', name: 'api_eco_teacher_course_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function startCourse(int $id, EntityManagerInterface $entityManager, EcoCourseRepository $repository): JsonResponse
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        if (!$course->start(new \DateTimeImmutable())) {
            return $this->json(['error' => 'courseNotPrepared', 'course' => $this->formatCourse($course)], 409);
        }
        $entityManager->flush();

        return $this->json(['course' => $this->formatCourse($course)]);
    }

    #[Route(path: '/api/eco/teacher/courses/{id}/close', name: 'api_eco_teacher_course_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function closeCourse(int $id, EntityManagerInterface $entityManager, EcoCourseRepository $repository): JsonResponse
    {
        $course = $this->findCourseOrNotFound($repository, $id);
        if (!$course->close(new \DateTimeImmutable())) {
            return $this->json(['error' => 'courseNotInProgress', 'course' => $this->formatCourse($course)], 409);
        }
        $entityManager->flush();

        return $this->json(['course' => $this->formatCourse($course)]);
    }

    // Entry list for screen 4d - which InProgress courses this teacher can even monitor.
    #[Route(path: '/api/eco/teacher/courses/in-progress', name: 'api_eco_teacher_courses_in_progress', methods: ['GET'])]
    public function coursesInProgress(EcoCourseRepository $repository): JsonResponse
    {
        $courses = $repository->findInProgressForTeacher($this->currentUser());

        return $this->json([
            'courses' => array_map(fn (EcoCourse $course): array => $this->formatCourse($course), $courses),
        ]);
    }

    #[Route(path: '/api/eco/teacher/courses/{id}/live', name: 'api_eco_teacher_course_live', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function courseLive(int $id, EcoCourseRepository $repository, EcoLiveTrackingService $liveTracking): JsonResponse
    {
        $course = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $course->getParcours());

        $runners = $liveTracking->sortedBySeverity($course->getRunners()->toArray());

        $startedAt = $course->getStartedAt();

        return $this->json([
            'courseName' => $course->getName(),
            'courseCode' => $course->getCode(),
            'status' => $course->getStatus()->value,
            // What screen 4d's header states: how long the race has been running, and the
            // checkpoints the map draws the runners against.
            'elapsedMinutes' => null !== $startedAt ? intdiv((new \DateTimeImmutable())->getTimestamp() - $startedAt->getTimestamp(), 60) : null,
            'checkpoints' => array_values(array_map(
                fn (EcoCheckpoint $checkpoint): array => $this->formatCheckpoint($checkpoint),
                array_filter($course->getParcours()->getCheckpoints()->toArray(), static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->isLocated()),
            )),
            'runners' => array_map(static fn ($runner): array => $liveTracking->runnerLiveRow($runner), $runners),
        ]);
    }

    /** @return array<string, mixed> */
    private function formatCourse(EcoCourse $course): array
    {
        return [
            'id' => $course->getId(),
            'name' => $course->getName(),
            'code' => $course->getCode(),
            'parcoursId' => $course->getParcours()->getId(),
            'parcoursName' => $course->getParcours()->getName(),
            'status' => $course->getStatus()->value,
            'statusLabel' => $this->translator->trans($course->getStatus()->labelKey()),
            'mode' => $course->getMode()->value,
            'modeLabel' => $this->translator->trans($course->getMode()->labelKey()),
            'timeLimitMinutes' => $course->getTimeLimitMinutes(),
            'mapVisibility' => $course->getMapVisibility()->value,
            'teamsEnabled' => $course->isTeamsEnabled(),
            'safetyAlertsEnabled' => $course->isSafetyAlertsEnabled(),
            'runnerCount' => $course->getRunners()->count(),
            'createdAt' => $course->getCreationDate()->format(\DateTimeInterface::ATOM),
            'startedAt' => $course->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'closedAt' => $course->getClosedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function formatCheckpoint(EcoCheckpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->getId(),
            'name' => $checkpoint->getName(),
            // The landmark the teacher wrote down ("passerelle"): screen 4b shows it beside the
            // name, so the right flag is planted in the right place.
            'note' => $checkpoint->getNote(),
            'shortCode' => $checkpoint->getShortCode(),
            'position' => $checkpoint->getPosition(),
            'type' => $checkpoint->getType()->value,
            'toleranceMeters' => $checkpoint->getToleranceMeters(),
            'located' => $checkpoint->isLocated(),
            'latitude' => $checkpoint->getLatitude(),
            'longitude' => $checkpoint->getLongitude(),
            'locatedAt' => $checkpoint->getLocatedAt()?->format(\DateTimeInterface::ATOM),
            // The IGN's reading of the ground under the flag (screen 4c): null until it has answered.
            'groundAltitude' => $checkpoint->getGroundAltitude(),
            'canopyHeight' => $checkpoint->getCanopyHeight(),
            'advisedToleranceMeters' => $this->toleranceAdvisor->adviceFor($checkpoint),
        ];
    }

    private function findParcoursOrNotFound(EcoParcoursRepository $repository, int $id): EcoParcours
    {
        $parcours = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $parcours);

        return $parcours;
    }

    // Courses run only on a parcours whose every flag is located - the web screen 1g says the same.
    private function findReadyParcoursOrNotFound(EcoParcoursRepository $repository, int $id): EcoParcours
    {
        $parcours = $this->findParcoursOrNotFound($repository, $id);
        if (!$parcours->isReady()) {
            throw $this->createNotFoundException();
        }

        return $parcours;
    }

    private function findCourseOrNotFound(EcoCourseRepository $repository, int $id): EcoCourse
    {
        $course = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(EcoParcoursVoter::EDIT, $course->getParcours());

        return $course;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
