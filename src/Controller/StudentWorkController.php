<?php

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\AssignmentCompletion;
use App\Entity\AssignmentDismissal;
use App\Entity\AssignmentSubmission;
use App\Entity\AssignmentSubmissionFile;
use App\Entity\AssignmentView;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\StudentWorkState;
use App\Form\AssignmentSubmissionFileType;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentDismissalRepository;
use App\Repository\AssignmentExpectedProductionRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\AssignmentViewRepository;
use App\Service\AssignmentAudienceResolver;
use App\Service\AssignmentGradebookLinker;
use App\Service\FileUploadService;
use App\Service\StudentWorkBoard;
use App\Service\StudentWorkItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Travail à faire" (design_handoff_travail_a_faire, screen 3c): everything a student has to do,
 * every subject and every session taken together.
 *
 * Cuts across programs, unlike App\Controller\ProgramAssignmentSubmissionController which remains
 * one assignment's own page. Deposits happen here, one per expected production, from the consigne
 * modal - this is the student's way in, and handing something in should not ask them to leave it.
 */
#[IsGranted('ROLE_STUDENT')]
class StudentWorkController extends AbstractController
{
    private const string SUBMISSION_UPLOAD_PREFIX = 'assignment-submissions/';

    /** How many finished assignments "Derniers travaux" holds before sending on to the history. */
    private const int RECENT_LIMIT = 6;

    #[Route(path: '/travail-a-realiser', name: 'app_student_work')]
    public function index(Request $request, StudentWorkBoard $board): Response
    {
        $student = $this->currentUser();
        $now = new \DateTimeImmutable();
        $items = $board->build($student, $now);
        $visible = $this->filteredByTopic($items, $topicFilter = $request->query->getInt('matiere'));

        // Two groups only, both chronological and each cut by day - the date line does not repeat
        // when several assignments fall on the same day.
        $late = $this->sortedByDueDate(array_filter($visible, static fn (StudentWorkItem $i): bool => StudentWorkState::Late === $i->state));
        $todo = $this->sortedByDueDate(array_filter($visible, static fn (StudentWorkItem $i): bool => \in_array($i->state, [StudentWorkState::Todo, StudentWorkState::Submitted, StudentWorkState::Dismissed], true)));

        return $this->render('student/work.html.twig', [
            'lateDays' => $this->groupByDay($late),
            'todoDays' => $this->groupByDay($todo),
            'recent' => \array_slice($this->recent($visible), 0, self::RECENT_LIMIT),
            'topics' => $this->topicsOf($items),
            'topicFilter' => $topicFilter,
            'now' => $now,
        ]);
    }

    /**
     * "Voir les travaux": everything now behind the student - handed in, done, or left unhandled -
     * where the side column only keeps the latest few.
     */
    #[Route(path: '/travail-a-realiser/historique', name: 'app_student_work_history')]
    public function history(Request $request, StudentWorkBoard $board): Response
    {
        $items = $board->build($this->currentUser());
        $visible = $this->filteredByTopic($items, $topicFilter = $request->query->getInt('matiere'));

        return $this->render('student/work_history.html.twig', [
            'items' => $this->recent($visible),
            'topics' => $this->topicsOf($items),
            'topicFilter' => $topicFilter,
        ]);
    }

    /**
     * "Voir la consigne" (3b): the brief, the requested deposits with their own constraints and
     * deadlines, and the supporting attachments. Served as a fragment, the modal being fetched on
     * demand from the list.
     *
     * Opening the brief is taking notice of the assignment: the trace is written here, and it is
     * what feeds the teacher's read tracking.
     */
    #[Route(path: '/travail-a-realiser/{assignmentId}/consigne', name: 'app_student_work_brief', methods: ['GET'], requirements: ['assignmentId' => '\d+'])]
    public function brief(int $assignmentId, EntityManagerInterface $entityManager, StudentWorkBoard $board, AssignmentRepository $assignmentRepository, AssignmentViewRepository $viewRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        $student = $this->currentUser();
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId, $assignmentRepository, $audienceResolver);

        $view = $viewRepository->findOneFor($assignment, $student);
        $view ? $view->registerView() : $entityManager->persist(new AssignmentView($assignment, $student));
        $entityManager->flush();

        return $this->render('student/_work_brief.html.twig', [
            'item' => $this->itemOrNotFound($board, $assignment),
        ]);
    }

    /**
     * One file handed in for one expected production - or for the assignment as a whole, when it
     * spells none out. One deposit per expectation, each with its own deadline (3b).
     */
    #[Route(path: '/travail-a-realiser/{assignmentId}/deposer/{productionId}', name: 'app_student_work_submit', methods: ['POST'], requirements: ['assignmentId' => '\d+', 'productionId' => '\d+'])]
    #[Route(path: '/travail-a-realiser/{assignmentId}/deposer', name: 'app_student_work_submit_global', methods: ['POST'], requirements: ['assignmentId' => '\d+'])]
    public function submit(int $assignmentId, Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator, AssignmentRepository $assignmentRepository, AssignmentSubmissionRepository $submissionRepository, AssignmentExpectedProductionRepository $productionRepository, AssignmentAudienceResolver $audienceResolver, FileUploadService $fileUploadService, AssignmentGradebookLinker $gradebookLinker, ?int $productionId = null): Response
    {
        if (!$this->isCsrfTokenValid('student_work_submit', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student = $this->currentUser();
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId, $assignmentRepository, $audienceResolver);

        if (!$assignment->expectsSubmission()) {
            throw $this->createAccessDeniedException();
        }

        $production = null;
        if (null !== $productionId) {
            $production = $productionRepository->find($productionId) ?? throw $this->createNotFoundException();

            if ($production->getAssignment()?->getId() !== $assignment->getId()) {
                throw $this->createNotFoundException();
            }
        }

        // A deadline gone by with no late submission allowed shuts the deposit: the mockup drops
        // the button, and the route holds to that even if a stale form was left open.
        $due = $production?->getEffectiveDueDate() ?? $assignment->getDueDate();
        if (!$assignment->isLateSubmissionAllowed() && null !== $due && $due < new \DateTimeImmutable()) {
            throw $this->createAccessDeniedException();
        }

        // No form object here: the mockup shows a lone "Déposer" button per expectation, so the
        // file arrives from a hidden input that submits as soon as one is picked. The constraint
        // is still the one AssignmentSubmissionFileType applies on the assignment's own page.
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            throw $this->createNotFoundException();
        }

        $violations = $validator->validate($file, AssignmentSubmissionFileType::fileConstraint());

        if ($violations->count() > 0) {
            $this->addFlash('danger', $violations->get(0)->getMessage());

            return $this->redirectToRoute('app_student_work', $request->query->all());
        }

        $submission = $submissionRepository->findOneForAssignmentAndStudent($assignment, $student, $production)
            ?? new AssignmentSubmission($assignment, $student, $production);

        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $key = $fileUploadService->upload(
            self::SUBMISSION_UPLOAD_PREFIX,
            sprintf('%d-%d-%s.%s', $assignment->getId(), $student->getId(), bin2hex(random_bytes(4)), $extension),
            $file,
        );

        // "Une évaluation est créée automatiquement dans le carnet de notes à la réception des
        // rendus" (2a) - on receipt, so here, and not when the assignment is published.
        $gradebookLinker->ensureEvaluationExists($assignment);

        $entityManager->persist($submission);
        $entityManager->persist(new AssignmentSubmissionFile($submission, $key, $file->getClientOriginalName()));
        $entityManager->flush();

        $this->addFlash('success', 'assignmentSubmissionUploadedFlashMessage');

        return $this->redirectToRoute('app_student_work', $request->query->all());
    }

    /**
     * "Marquer comme fait" and its undo: a row appears or disappears, no row meaning "à faire".
     * Kept to assignments with neither a deposit nor a sitting, which carry their own proof of
     * completion.
     */
    #[Route(path: '/travail-a-realiser/{assignmentId}/fait', name: 'app_student_work_done', methods: ['POST'], requirements: ['assignmentId' => '\d+'])]
    public function toggleDone(int $assignmentId, Request $request, EntityManagerInterface $entityManager, AssignmentRepository $assignmentRepository, AssignmentCompletionRepository $completionRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        if (!$this->isCsrfTokenValid('student_work_done', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student = $this->currentUser();
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId, $assignmentRepository, $audienceResolver);

        if (!$assignment->getNature()->expectsSelfDeclaration()) {
            throw $this->createAccessDeniedException();
        }

        $existing = $completionRepository->findOneFor($assignment, $student);
        $existing ? $entityManager->remove($existing) : $entityManager->persist(new AssignmentCompletion($assignment, $student));
        $entityManager->flush();

        return $this->redirectToRoute('app_student_work', $request->query->all());
    }

    /**
     * "Ignorer" and "Rétablir": the assignment is no longer flagged as late. Due in the future it
     * stays visible, greyed out; already late it leaves the list. Nothing is claimed done for all
     * that - the trace is a separate one, see App\Entity\AssignmentDismissal.
     */
    #[Route(path: '/travail-a-realiser/{assignmentId}/ignorer', name: 'app_student_work_dismiss', methods: ['POST'], requirements: ['assignmentId' => '\d+'])]
    public function toggleDismissed(int $assignmentId, Request $request, EntityManagerInterface $entityManager, AssignmentRepository $assignmentRepository, AssignmentDismissalRepository $dismissalRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        if (!$this->isCsrfTokenValid('student_work_dismiss', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student = $this->currentUser();
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId, $assignmentRepository, $audienceResolver);

        $existing = $dismissalRepository->findOneFor($assignment, $student);
        $existing ? $entityManager->remove($existing) : $entityManager->persist(new AssignmentDismissal($assignment, $student));
        $entityManager->flush();

        return $this->redirectToRoute('app_student_work', $request->query->all());
    }

    /**
     * What is behind the student, most recent first: what went unhandled comes first - the mockup
     * puts it at the head of the column - then what was handed in.
     *
     * @param list<StudentWorkItem> $items
     *
     * @return list<StudentWorkItem>
     */
    private function recent(array $items): array
    {
        $missed = $this->sortedByDueDate(array_filter($items, static fn (StudentWorkItem $i): bool => StudentWorkState::Missed === $i->state), false);
        $done = $this->sortedByDueDate(array_filter($items, static fn (StudentWorkItem $i): bool => StudentWorkState::Done === $i->state), false);

        return array_merge($missed, $done);
    }

    /**
     * @param list<StudentWorkItem> $items
     *
     * @return list<StudentWorkItem>
     */
    private function filteredByTopic(array $items, int $topicId): array
    {
        return array_values(array_filter(
            $items,
            static fn (StudentWorkItem $item): bool => 0 === $topicId || $item->assignment->getTopic()?->getId() === $topicId,
        ));
    }

    /**
     * @param array<int, StudentWorkItem> $items
     *
     * @return list<StudentWorkItem>
     */
    private function sortedByDueDate(array $items, bool $ascending = true): array
    {
        $sorted = array_values($items);
        usort($sorted, static fn (StudentWorkItem $a, StudentWorkItem $b): int => $ascending
            ? $a->dueDate <=> $b->dueDate
            : $b->dueDate <=> $a->dueDate);

        return $sorted;
    }

    /**
     * Assignments filed by due day, in order - the template draws a single date line per day from
     * this, however many rows follow it.
     *
     * @param list<StudentWorkItem> $items
     *
     * @return list<array{date: \DateTimeImmutable, items: list<StudentWorkItem>}>
     */
    private function groupByDay(array $items): array
    {
        $days = [];
        foreach ($items as $item) {
            $days[$item->dueDay()]['date'] ??= $item->dueDate;
            $days[$item->dueDay()]['items'][] = $item;
        }

        return array_values($days);
    }

    /**
     * The subjects present in a student's assignments, for the "Toutes les matières" filter. An
     * assignment with no subject attached cannot be filtered: it only reads under "toutes".
     *
     * @param list<StudentWorkItem> $items
     *
     * @return list<Topic>
     */
    private function topicsOf(array $items): array
    {
        $topics = [];
        foreach ($items as $item) {
            $topic = $item->assignment->getTopic();

            if (null !== $topic) {
                $topics[$topic->getId()] = $topic;
            }
        }

        $topics = array_values($topics);
        usort($topics, static fn (Topic $a, Topic $b): int => $a->getName() <=> $b->getName());

        return $topics;
    }

    private function findVisibleAssignmentOrNotFound(int $assignmentId, AssignmentRepository $repository, AssignmentAudienceResolver $audienceResolver): Assignment
    {
        $assignment = $repository->find($assignmentId) ?? throw $this->createNotFoundException();

        // An unpublished assignment does not exist yet for the student, and a published one only
        // concerns them if they are part of its audience.
        if (!$assignment->isVisibleFor() || !$audienceResolver->isInAudience($assignment, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    /** Where the assignment stands for this student, as the list reads it - the modal shows the same. */
    private function itemOrNotFound(StudentWorkBoard $board, Assignment $assignment): StudentWorkItem
    {
        foreach ($board->build($this->currentUser()) as $item) {
            if ($item->assignment->getId() === $assignment->getId()) {
                return $item;
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
