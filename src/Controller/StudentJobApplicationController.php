<?php

namespace App\Controller;

use App\Entity\JobSearchNote;
use App\Entity\User;
use App\Repository\JobApplicationRepository;
use App\Repository\JobSearchNoteRepository;
use App\Repository\JobSearchRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;
use App\Service\JobApplicationSummaryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Démarches de <student>" - the staff sheet for one student
 * (design_handoff_stage_alternance, screen 2a).
 *
 * Applications grouped by company, their mails expandable in read-only, factual counters, and the
 * team's own notes. Read-only is the whole point on this side: a teacher reads what was exchanged,
 * they never write in the student's place - a reply is written from the student's own mailbox.
 *
 * The notes are invisible to the student (see App\Entity\JobSearchNote), which is why this screen
 * and screen 2b never share a template even though both list the same applications.
 */
class StudentJobApplicationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ProgramRepository $programRepository,
        private readonly JobApplicationRepository $applicationRepository,
        private readonly JobSearchRepository $searchRepository,
        private readonly JobSearchNoteRepository $noteRepository,
        private readonly JobApplicationSummaryBuilder $summaryBuilder,
        private readonly StructureAccessChecker $accessChecker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/students/{id}/demarches', name: 'app_student_job_applications', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $student = $this->findOrDenyAccess($id);

        $rows = [];
        $counters = ['applications' => 0, 'replies' => 0, 'failed' => 0, 'followUps' => 0];

        foreach ($this->applicationRepository->findForStudent($student) as $application) {
            $summary = $this->summaryBuilder->summarize($application);

            ++$counters['applications'];
            $counters['replies'] += null !== $summary['replyAt'] ? 1 : 0;
            $counters['failed'] += $summary['failed'] ? 1 : 0;
            // "Relances à faire": an application whose chip says it has been waiting past the
            // follow-up delay. A count of days, not a reading of anything.
            $counters['followUps'] += 'waiting' === ($summary['chip']['variant'] ?? null) ? 1 : 0;

            $rows[] = [
                'application' => $application,
                'summary' => $summary,
                'accent' => $application->getEnterprise()->getId() % 5,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => ($right['summary']['lastActivityAt'] <=> $left['summary']['lastActivityAt']));

        return $this->render('job_application/student_sheet.html.twig', [
            'student' => $student,
            // The class the sheet is read from, for the breadcrumb's parent segment: this screen is
            // reached from one class's tracking page, and going back up has to land there.
            'program' => $this->visibleProgramFor($student),
            'rows' => $rows,
            'counters' => $counters,
            'notes' => $this->noteRepository->findForStudent($student),
            'closedSearch' => $this->searchRepository->findOneBy(['student' => $student]),
        ]);
    }

    #[Route(path: '/students/{id}/demarches/notes', name: 'app_student_job_application_note_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addNote(int $id, Request $request): Response
    {
        $student = $this->findOrDenyAccess($id);

        if (!$this->isCsrfTokenValid('job_search_note', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $body = trim((string) $request->request->get('body', ''));

        if ('' !== $body) {
            /** @var User $author */
            $author = $this->getUser();

            $note = (new JobSearchNote())
                ->setStudent($student)
                ->setAuthor($author)
                ->setBody($body);

            $this->entityManager->persist($note);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_student_job_applications', ['id' => $student->getId()]);
    }

    #[Route(path: '/students/{id}/demarches/notes/{noteId}/supprimer', name: 'app_student_job_application_note_delete', requirements: ['id' => '\d+', 'noteId' => '\d+'], methods: ['POST'])]
    public function deleteNote(int $id, int $noteId, Request $request): Response
    {
        $student = $this->findOrDenyAccess($id);

        if (!$this->isCsrfTokenValid('job_search_note_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $note = $this->noteRepository->find($noteId);

        if (null !== $note && $note->getStudent()?->getId() === $student->getId()) {
            $this->entityManager->remove($note);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_student_job_applications', ['id' => $student->getId()]);
    }

    /** The first class of this student the viewer may see - null when they share none. */
    private function visibleProgramFor(User $student): ?\App\Entity\Program
    {
        foreach ($this->programRepository->findAllActiveForStudent($student) as $program) {
            if ($this->accessChecker->isProgramVisible($program)) {
                return $program;
            }
        }

        return null;
    }

    /**
     * Staff see every student; a teacher only the students of a class they can already see. Reusing
     * StructureAccessChecker rather than inventing a rule here keeps this screen exactly as reachable
     * as the class pages it is opened from.
     */
    private function findOrDenyAccess(int $id): User
    {
        $student = $this->userRepository->find($id) ?? throw $this->createNotFoundException();

        if ($this->accessChecker->isStaff()) {
            return $student;
        }

        if (!$this->isGranted('ROLE_TEACHER')) {
            throw $this->createAccessDeniedException();
        }

        foreach ($this->programRepository->findAllActiveForStudent($student) as $program) {
            if ($this->accessChecker->isProgramVisible($program)) {
                return $student;
            }
        }

        throw $this->createAccessDeniedException();
    }
}
