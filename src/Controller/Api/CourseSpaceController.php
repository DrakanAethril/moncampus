<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Attribute\RequiresFeature;
use App\Entity\LibraryResourceInstance;
use App\Entity\LibraryResourceInstanceView;
use App\Entity\Program;
use App\Entity\SequenceInstance;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\LibraryResourceSourceType;
use App\Repository\LibraryResourceInstanceRepository;
use App\Repository\LibraryResourceInstanceViewRepository;
use App\Repository\ProgramRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\Voter\SequenceInstanceVoter;
use App\Service\AccessConditionGate;
use App\Service\CourseSpaceBoard;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mes cours » for the mobile app - the séquences a student may read, their séances, and the
 * resources hanging off them.
 *
 * The web side shipped with the course space; this did not, so the whole area was simply absent from
 * the app. Every rule it needs already exists and none of it is restated here: App\Service\CourseSpaceBoard
 * answers what is readable (publication first, then access conditions),
 * App\Security\Voter\SequenceInstanceVoter answers whether this reader may open a given séquence,
 * and App\Service\AccessConditionGate answers whether a resource is unlocked. Re-deciding any of
 * that here is how the app and the site come to disagree about the same course.
 *
 * Three GETs and one opening, mirroring App\Controller\StudentCourseSpaceController one for one.
 */
#[IsGranted('ROLE_STUDENT')]
#[RequiresFeature(Feature::CourseSpace)]
class CourseSpaceController extends AbstractController
{
    /** The student's classes - the list « Mes cours » opens on. */
    #[Route(path: '/api/courses', name: 'api_courses', methods: ['GET'])]
    public function index(ProgramRepository $programRepository): JsonResponse
    {
        return $this->json([
            'programs' => array_map(
                static fn (Program $program): array => [
                    'id' => $program->getId(),
                    'name' => $program->getDisplayShortName(),
                ],
                $programRepository->findAllActiveForStudent($this->currentUser()),
            ),
        ]);
    }

    /**
     * One class: its séquences, with the number of séances each holds.
     *
     * A locked séquence is *sent* rather than dropped - it stays in the list with the gesture that
     * opens it, which an absent row cannot express. An unpublished one never reaches this point at
     * all: CourseSpaceBoard removed it before the gate was even asked.
     */
    #[Route(path: '/api/courses/{programId}', name: 'api_courses_program', requirements: ['programId' => '\d+'], methods: ['GET'])]
    public function program(int $programId, ProgramRepository $programRepository, CourseSpaceBoard $board): JsonResponse
    {
        $student = $this->currentUser();
        $program = $this->programOrNotFound($programId, $programRepository);

        $sequences = $board->sequencesFor($program, $student);
        $verdicts = $board->accessVerdicts($sequences, $student);

        return $this->json([
            'program' => ['id' => $program->getId(), 'name' => $program->getDisplayShortName()],
            'sequences' => array_map(
                static fn (SequenceInstance $sequence): array => [
                    'id' => $sequence->getId(),
                    'title' => $sequence->getTitre(),
                    'seanceCount' => \count($board->seancesFor($sequence)),
                    'locked' => !$verdicts->isOpen($sequence),
                    'lockedReasons' => $verdicts->isOpen($sequence) ? [] : $verdicts->reasonsFor($sequence),
                ],
                $sequences,
            ),
        ]);
    }

    /** One séquence: its séances and, under each, the resources this student may see. */
    #[Route(path: '/api/courses/{programId}/sequences/{id}', name: 'api_courses_sequence', requirements: ['programId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function sequence(
        int $programId,
        int $id,
        SequenceInstanceRepository $sequenceInstanceRepository,
        CourseSpaceBoard $board,
    ): JsonResponse {
        $student = $this->currentUser();
        $sequence = $sequenceInstanceRepository->find($id) ?? throw $this->createNotFoundException();

        if ($sequence->getProgram()?->getId() !== $programId) {
            throw $this->createNotFoundException();
        }

        // Publication *and* access condition, both of them, decided by the same voter the web screen
        // consults - the app must never be the way round a lock.
        $this->denyAccessUnlessGranted(SequenceInstanceVoter::VIEW, $sequence);

        $seances = [];
        $listed = [];
        foreach ($board->seancesFor($sequence) as $seance) {
            $resources = $board->resourcesFor($seance, $student);
            $listed = array_merge($listed, $resources);
            $seances[] = ['seance' => $seance, 'resources' => $resources];
        }

        // One call for the whole payload rather than one per resource, exactly as the page does.
        $verdicts = $board->accessVerdicts($listed, $student);
        $opened = $board->openedResourceIds($listed, $student);

        return $this->json([
            'sequence' => ['id' => $sequence->getId(), 'title' => $sequence->getTitre()],
            'seances' => array_map(
                static fn (array $row): array => [
                    'id' => $row['seance']->getId(),
                    'title' => $row['seance']->getTitre(),
                    'order' => $row['seance']->getOrdre(),
                    'resources' => array_map(
                        static fn (LibraryResourceInstance $resource): array => [
                            'id' => $resource->getId(),
                            'label' => $resource->getLabel(),
                            'type' => $resource->getType()->value,
                            // Never the storage address: an upload is reached through the opening
                            // route below, which is also the only place that can tell « listed »
                            // from « opened ».
                            'opened' => isset($opened[(int) $resource->getId()]),
                            'locked' => !$verdicts->isOpen($resource),
                            'lockedReasons' => $verdicts->isOpen($resource) ? [] : $verdicts->reasonsFor($resource),
                        ],
                        $row['resources'],
                    ),
                ],
                $seances,
            ),
        ]);
    }

    /**
     * Opening a resource: the trace is written, and the address is handed back rather than
     * redirected to.
     *
     * A JSON client cannot follow a 302 into an S3 URL and still know it worked, so this answers
     * with the target and lets the app open it. The three checks are the web route's, in the same
     * order - the séquence readable, the resource published, the condition open.
     */
    #[Route(path: '/api/courses/resources/{id}/open', name: 'api_courses_resource_open', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function openResource(
        int $id,
        LibraryResourceInstanceRepository $resourceRepository,
        LibraryResourceInstanceViewRepository $viewRepository,
        FileUploadService $fileUploadService,
        EntityManagerInterface $entityManager,
        AccessConditionGate $accessGate,
    ): JsonResponse {
        $student = $this->currentUser();
        $resource = $resourceRepository->find($id) ?? throw $this->createNotFoundException();
        $sequence = $this->sequenceOf($resource) ?? throw $this->createNotFoundException();

        $this->denyAccessUnlessGranted(SequenceInstanceVoter::VIEW, $sequence);

        if (!$resource->isStudentVisible()) {
            throw $this->createNotFoundException();
        }

        if (!$accessGate->isOpen($resource, $student)) {
            return $this->json(['error' => 'resource_locked'], Response::HTTP_CONFLICT);
        }

        $view = $viewRepository->findOneFor($resource, $student);
        if (null === $view) {
            $entityManager->persist(new LibraryResourceInstanceView($resource, $student));
        } else {
            $view->registerOpening();
        }
        $entityManager->flush();

        return $this->json([
            'url' => LibraryResourceSourceType::Link === $resource->getType()
                ? (string) $resource->getUrl()
                : $fileUploadService->url((string) $resource->getStorageKey()),
        ]);
    }

    private function programOrNotFound(int $programId, ProgramRepository $programRepository): Program
    {
        foreach ($programRepository->findAllActiveForStudent($this->currentUser()) as $program) {
            if ($program->getId() === $programId) {
                return $program;
            }
        }

        throw $this->createNotFoundException();
    }

    /** A resource hangs off exactly one of the three levels; only the séquence answers for access. */
    private function sequenceOf(LibraryResourceInstance $resource): ?SequenceInstance
    {
        return $resource->getSequenceInstance()
            ?? $resource->getSeanceInstance()?->getSequenceInstance()
            ?? $resource->getSeancePhaseInstance()?->getSeanceInstance()?->getSequenceInstance();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
