<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LibraryResourceInstance;
use App\Entity\LibraryResourceInstanceView;
use App\Entity\Program;
use App\Entity\SequenceInstance;
use App\Entity\User;
use App\Enum\LibraryResourceSourceType;
use App\Repository\LibraryResourceInstanceRepository;
use App\Repository\LibraryResourceInstanceViewRepository;
use App\Repository\ProgramRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SequenceInstanceVoter;
use App\Service\AccessConditionGate;
use App\Service\CourseSpaceBoard;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mes cours" - the student's way into the teaching sequences of their programs.
 *
 * Adds no content model of its own: SequenceInstance, SeanceInstance and LibraryResourceInstance
 * already existed and were simply never shown to a student. What is new is the publication flag
 * they now carry and this reading of it, which is why every screen here is read-only.
 *
 * The sequence and séance screens are open to teaching staff too - that is what makes "Aperçu
 * étudiant" possible - and the split is decided by SequenceInstanceVoter, never here.
 */
class StudentCourseSpaceController extends AbstractController
{
    #[Route(path: '/my/courses', name: 'app_my_courses')]
    #[IsGranted('ROLE_STUDENT')]
    public function index(ProgramRepository $programRepository): Response
    {
        return $this->render('course_space/index.html.twig', [
            'programs' => $programRepository->findAllActiveForStudent($this->currentUser()),
        ]);
    }

    #[Route(path: '/my/courses/{programId}', name: 'app_my_courses_program', requirements: ['programId' => '\d+'])]
    public function program(int $programId, ProgramRepository $programRepository, CourseSpaceBoard $board, StructureAccessChecker $accessChecker): Response
    {
        $program = $this->findVisibleProgramOrNotFound($programId, $programRepository, $accessChecker);

        $sequences = $board->sequencesFor($program, $this->currentUser());
        $seanceCounts = [];
        foreach ($sequences as $sequence) {
            $seanceCounts[(int) $sequence->getId()] = \count($board->seancesFor($sequence));
        }

        return $this->render('course_space/program.html.twig', [
            'program' => $program,
            'sequences' => $sequences,
            'seanceCounts' => $seanceCounts,
            'accessVerdicts' => $board->accessVerdicts($sequences, $this->currentUser()),
        ]);
    }

    #[Route(path: '/my/courses/{programId}/sequences/{id}', name: 'app_my_courses_sequence', requirements: ['programId' => '\d+', 'id' => '\d+'])]
    public function sequence(int $programId, int $id, SequenceInstanceRepository $sequenceInstanceRepository, CourseSpaceBoard $board): Response
    {
        $sequence = $sequenceInstanceRepository->find($id) ?? throw $this->createNotFoundException();

        if ($sequence->getProgram()?->getId() !== $programId) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SequenceInstanceVoter::VIEW, $sequence);

        $seances = $board->seancesFor($sequence);
        $resources = [];
        $opened = [];
        $listed = [];
        foreach ($seances as $seance) {
            $own = $board->resourcesFor($seance, $this->currentUser());
            $resources[(int) $seance->getId()] = $own;
            $opened += $board->openedResourceIds($own, $this->currentUser());
            $listed = array_merge($listed, $own);
        }

        return $this->render('course_space/sequence.html.twig', [
            'program' => $sequence->getProgram(),
            'sequence' => $sequence,
            'seances' => $seances,
            'resources' => $resources,
            'opened' => $opened,
            // One call for the whole page rather than one per resource: the rows that stayed are the
            // locked ones, and each of them prints what opens it.
            'accessVerdicts' => $board->accessVerdicts($listed, $this->currentUser()),
        ]);
    }

    /**
     * Opening a resource, and the trace that goes with it.
     *
     * Never a raw storage address in the page: an upload is reached through a redirect built here,
     * which is also the only place that can tell the difference between "the student saw this
     * resource listed" and "the student opened it". That difference is what the access conditions
     * of the course space will read later.
     */
    #[Route(path: '/my/courses/resources/{id}/open', name: 'app_my_courses_resource_open', requirements: ['id' => '\d+'])]
    public function openResource(
        int $id,
        LibraryResourceInstanceRepository $resourceRepository,
        LibraryResourceInstanceViewRepository $viewRepository,
        FileUploadService $fileUploadService,
        EntityManagerInterface $entityManager,
        AccessConditionGate $accessGate,
    ): RedirectResponse {
        $resource = $resourceRepository->find($id) ?? throw $this->createNotFoundException();
        $sequence = $this->sequenceOf($resource) ?? throw $this->createNotFoundException();

        $this->denyAccessUnlessGranted(SequenceInstanceVoter::VIEW, $sequence);

        if (!$resource->isStudentVisible() && !$this->isGranted('ROLE_TEACHER')) {
            throw $this->createNotFoundException();
        }

        // The lock is checked here and not only where the row is drawn: a greyed line names its
        // resource in the page, so the address is one click away from being tried by hand.
        if (!$accessGate->isOpen($resource, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        $this->recordOpening($resource, $viewRepository, $entityManager);

        $target = LibraryResourceSourceType::Link === $resource->getType()
            ? (string) $resource->getUrl()
            : $fileUploadService->url((string) $resource->getStorageKey());

        return $this->redirect($target);
    }

    private function recordOpening(
        LibraryResourceInstance $resource,
        LibraryResourceInstanceViewRepository $viewRepository,
        EntityManagerInterface $entityManager,
    ): void {
        $view = $viewRepository->findOneFor($resource, $this->currentUser());

        if (null === $view) {
            $entityManager->persist(new LibraryResourceInstanceView($resource, $this->currentUser()));
        } else {
            $view->registerOpening();
        }

        $entityManager->flush();
    }

    /** A resource hangs off exactly one of the three levels; only the sequence answers for access. */
    private function sequenceOf(LibraryResourceInstance $resource): ?SequenceInstance
    {
        return $resource->getSequenceInstance()
            ?? $resource->getSeanceInstance()?->getSequenceInstance()
            ?? $resource->getSeancePhaseInstance()?->getSeanceInstance()?->getSequenceInstance();
    }

    /**
     * Not found rather than forbidden: a program a reader has no business with should not confirm
     * its own existence, which is the stance the rest of the program-scoped screens already take.
     */
    private function findVisibleProgramOrNotFound(int $programId, ProgramRepository $programRepository, StructureAccessChecker $accessChecker): Program
    {
        $program = $programRepository->find($programId) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createNotFoundException();
        }

        return $program;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
