<?php

namespace App\Controller;

use App\Entity\JobSearch;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\EmailMessageRepository;
use App\Repository\JobSearchRepository;
use App\Repository\ProgramRepository;
use App\Repository\TrainingApplicationRepository;
use App\Repository\TrainingOfferRepository;
use App\Security\StructureAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Suivi des recherches" - a class's job search at a glance
 * (design_handoff_stage_alternance, screen 1a).
 *
 * Opened from the class menu, so the class is known and the page only ever shows that one. Every
 * figure here is a counter: mails sent, delivered, failed, replies received. Nothing reads what a
 * reply says - the mockup's own footnote says as much, and principle #1 of the handoff forbids it.
 *
 * "Marquer terminé" closes a student's search: their mailbox stays readable, sending is turned off
 * and they drop out of the reminders. It is neither a deletion nor an archive, which is why it is
 * undoable and records who closed it.
 */
class ProgramJobSearchController extends AbstractController
{
    /** The window of the mockup's first tile, "Envois - 30 jours". */
    private const string RECENT_WINDOW = '-30 days';

    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly EmailMessageRepository $messageRepository,
        private readonly JobSearchRepository $searchRepository,
        private readonly TrainingApplicationRepository $trainingApplicationRepository,
        private readonly TrainingOfferRepository $trainingOfferRepository,
        private readonly StructureAccessChecker $accessChecker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/programs/{id}/job-search-tracking', name: 'app_program_job_searches', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id): Response
    {
        $program = $this->findOrDenyAccess($id);
        $students = $this->sortedStudents($program);
        $stats = $this->messageRepository->statsForStudents($students);
        $closed = $this->searchRepository->findClosedIndexedByStudentId($students);

        $rows = [];
        $totals = ['delivered' => 0, 'failed' => 0, 'replies' => 0];

        foreach ($students as $student) {
            $studentStats = $stats[$student->getId()] ?? ['sent' => 0, 'delivered' => 0, 'failed' => 0, 'replies' => 0, 'lastSentAt' => null];

            $totals['delivered'] += $studentStats['delivered'];
            $totals['failed'] += $studentStats['failed'];
            $totals['replies'] += $studentStats['replies'];

            $rows[] = [
                'student' => $student,
                'stats' => $studentStats,
                'closedSearch' => $closed[$student->getId()] ?? null,
            ];
        }

        /** @var User $viewer */
        $viewer = $this->getUser();

        // Screen 8c: the practice applications waiting on this viewer, on top of the tracking page.
        // Only for someone who validates at least one offer - for anybody else the block would be
        // an empty box asking a question they cannot answer.
        $isValidator = $this->trainingOfferRepository->isValidator($viewer);

        // The whole class, in alphabetical order: this screen is read to find one student among
        // thirty, and a list that hides half of them behind a link would defeat that.
        return $this->render('program/job_searches.html.twig', [
            'trainingApplications' => $isValidator
                ? $this->trainingApplicationRepository->findAwaitingReview($students, $viewer)
                : [],
            'validatedOfferCount' => $isValidator ? $this->trainingOfferRepository->countValidatedOffersFor($viewer) : 0,
            'program' => $program,
            'rows' => $rows,
            'kpis' => [
                'recentSent' => $this->messageRepository->countSentForStudentsSince($students, new \DateTimeImmutable(self::RECENT_WINDOW)),
                'delivered' => $totals['delivered'],
                'failed' => $totals['failed'],
                'replies' => $totals['replies'],
            ],
        ]);
    }

    #[Route(path: '/programs/{id}/job-search-tracking/{studentId}/close', name: 'app_program_job_search_close', requirements: ['id' => '\d+', 'studentId' => '\d+'], methods: ['POST'])]
    public function close(int $id, int $studentId, Request $request): Response
    {
        $program = $this->findOrDenyAccess($id);
        $student = $this->findStudentOrFail($program, $studentId);

        if (!$this->isCsrfTokenValid('job_search_close', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (null === $this->searchRepository->findOneBy(['student' => $student])) {
            /** @var User $closedBy */
            $closedBy = $this->getUser();

            $search = (new JobSearch())
                ->setStudent($student)
                ->setClosedBy($closedBy);

            $this->entityManager->persist($search);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_program_job_searches', ['id' => $program->getId()]);
    }

    /**
     * Reopening a search. Not in the mockup, but closing one is a click away from the whole class
     * list: a closure made by mistake has to be undoable, otherwise the only way out is a database
     * query.
     */
    #[Route(path: '/programs/{id}/job-search-tracking/{studentId}/reopen', name: 'app_program_job_search_reopen', requirements: ['id' => '\d+', 'studentId' => '\d+'], methods: ['POST'])]
    public function reopen(int $id, int $studentId, Request $request): Response
    {
        $program = $this->findOrDenyAccess($id);
        $student = $this->findStudentOrFail($program, $studentId);

        if (!$this->isCsrfTokenValid('job_search_reopen', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $search = $this->searchRepository->findOneBy(['student' => $student]);

        if (null !== $search) {
            $this->entityManager->remove($search);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_program_job_searches', ['id' => $program->getId()]);
    }

    /** @return list<User> */
    private function sortedStudents(Program $program): array
    {
        $students = $program->getStudents()->toArray();

        usort($students, static fn (User $left, User $right): int => strcasecmp(
            ($left->getLastname() ?? '').' '.($left->getFirstname() ?? ''),
            ($right->getLastname() ?? '').' '.($right->getFirstname() ?? ''),
        ));

        return $students;
    }

    private function findStudentOrFail(Program $program, int $studentId): User
    {
        foreach ($program->getStudents() as $student) {
            if ($student->getId() === $studentId) {
                return $student;
            }
        }

        // Closing the search of a student who is not in this class would be closing it from a page
        // that has no business knowing them.
        throw $this->createNotFoundException();
    }

    /**
     * Staff-only, unlike the other class pages: this screen shows a whole class's correspondence
     * with companies, which the students themselves must never reach.
     */
    private function findOrDenyAccess(int $id): Program
    {
        $program = $this->programRepository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->accessChecker->isStaff() && !$this->isGranted('ROLE_TEACHER')) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }
}
