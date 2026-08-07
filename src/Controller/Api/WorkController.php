<?php

namespace App\Controller\Api;

use App\Entity\Assignment;
use App\Entity\AssignmentAttachment;
use App\Entity\AssignmentView;
use App\Entity\AudioRecordingFile;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentNature;
use App\Enum\StudentWorkState;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentViewRepository;
use App\Repository\ProgramRepository;
use App\Service\AssignmentAudienceResolver;
use App\Service\AssignmentProgressSummarizer;
use App\Service\AudioListenTracker;
use App\Service\AudioUploadService;
use App\Service\FileUploadService;
use App\Service\StudentWorkBoard;
use App\Service\StudentWorkExpectation;
use App\Service\StudentWorkItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mobile counterpart to StudentWorkController and AssignmentController::list() - the "Travaux" tab
 * of the app (design_handoff_mobile, screens 4a/4b/4c for students, 4d for teachers).
 *
 * Read-only on purpose. Submitting a file stays on the web ("dépôt de documents réservé au web",
 * handoff principe 3) and so does creating a travail ("côté enseignant, les travaux sont en
 * consultation", principe 10), so this controller exposes no write route at all - the one thing it
 * does persist is the "consigne opened" trace the teacher's read tracking feeds on, exactly like
 * the web brief does.
 *
 * The state machine is not reimplemented here: StudentWorkBoard is the single source of truth for
 * what is late/à faire/rendu, as on the web.
 */
class WorkController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The student's list (4b) and the home "Travaux" block (4a), which is the same payload cut to
     * its first rows client-side.
     *
     * Only the two groups the mockup draws are returned - late first, then à faire - and each item
     * carries the deadline its day separator is drawn from.
     */
    #[IsGranted('ROLE_STUDENT')]
    #[Route(path: '/api/student-work', name: 'api_student_work', methods: ['GET'])]
    public function studentWork(StudentWorkBoard $board): JsonResponse
    {
        $items = $board->build($student = $this->currentUser());

        $listed = array_values(array_filter(
            $items,
            static fn (StudentWorkItem $item): bool => \in_array(
                $item->state,
                [StudentWorkState::Late, StudentWorkState::Todo, StudentWorkState::Submitted],
                true,
            ),
        ));

        usort($listed, static fn (StudentWorkItem $a, StudentWorkItem $b): int => $a->dueDate <=> $b->dueDate);

        return $this->json([
            'subjects' => $this->subjectsOf($listed),
            'items' => array_map(fn (StudentWorkItem $item): array => $this->formatStudentItem($item), $listed),
            'displayName' => $student->getFirstname() ?? $student->getUsername(),
        ]);
    }

    /**
     * One travail in the consultation sheet (4c): the brief, the deposits it asks for - each with
     * its constraints or, once handed in, its file - and the attachments.
     *
     * Opening the sheet is taking notice of the travail, same as opening the web modal: the trace
     * is written here too, otherwise a student reading only on their phone would look like they
     * never opened it in the teacher's follow-up.
     */
    #[IsGranted('ROLE_STUDENT')]
    #[Route(path: '/api/student-work/{assignmentId}', name: 'api_student_work_show', methods: ['GET'], requirements: ['assignmentId' => '\d+'])]
    public function studentWorkDetail(
        int $assignmentId,
        StudentWorkBoard $board,
        AssignmentRepository $assignmentRepository,
        AssignmentViewRepository $viewRepository,
        AssignmentAudienceResolver $audienceResolver,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        AudioListenTracker $listenTracker,
        AudioUploadService $audioUploadService,
    ): JsonResponse {
        $student = $this->currentUser();
        $assignment = $assignmentRepository->find($assignmentId);

        if (null === $assignment || !$audienceResolver->isInAudience($assignment, $student)) {
            throw $this->createNotFoundException();
        }

        $item = null;
        foreach ($board->build($student) as $candidate) {
            if ($candidate->assignment->getId() === $assignment->getId()) {
                $item = $candidate;
                break;
            }
        }

        if (null === $item) {
            throw $this->createNotFoundException();
        }

        $view = $viewRepository->findOneFor($assignment, $student);
        $view ? $view->registerView() : $entityManager->persist(new AssignmentView($assignment, $student));
        $entityManager->flush();

        return $this->json($this->formatStudentItem($item) + [
            'description' => $assignment->getDescription(),
            'givenAt' => $assignment->getVisibleAt()?->format(\DateTimeInterface::ATOM),
            'expectations' => array_map(
                fn (StudentWorkExpectation $expectation): array => $this->formatExpectation($assignment, $expectation),
                $item->expectations,
            ),
            'attachments' => array_map(
                fn (AssignmentAttachment $attachment): array => $this->formatAttachment($attachment, $fileUploadService),
                $assignment->getAttachments()->toArray(),
            ),
            'audioFiles' => $this->formatAudioFiles($assignment, $student, $listenTracker, $audioUploadService),
        ]);
    }

    /**
     * The listening reported by the mobile player - the twin of
     * App\Controller\StudentWorkController::registerAudioListenProgress(), calling the same
     * AudioListenTracker. The handoff asks for it explicitly: the same events and the same
     * completion rules whichever player the student used.
     *
     * Unlike everything else in this controller, this route does write - listening is the proof of
     * completion of a Listening assignment, exactly as a deposit is that of a submission, and a
     * student listening only on their phone would otherwise never finish anything.
     */
    #[IsGranted('ROLE_STUDENT')]
    #[Route(path: '/api/student-work/{assignmentId}/audio/{fileId}/listen-progress', name: 'api_student_work_audio_listen_progress', methods: ['POST'], requirements: ['assignmentId' => '\d+', 'fileId' => '\d+'])]
    public function registerAudioListenProgress(
        int $assignmentId,
        int $fileId,
        Request $request,
        AssignmentRepository $assignmentRepository,
        AssignmentAudienceResolver $audienceResolver,
        AudioListenTracker $listenTracker,
    ): JsonResponse {
        $student = $this->currentUser();
        $assignment = $assignmentRepository->find($assignmentId);

        if (null === $assignment || !$assignment->isVisibleFor() || !$audienceResolver->isInAudience($assignment, $student)) {
            throw $this->createNotFoundException();
        }

        $recording = $assignment->getAudioRecording() ?? throw $this->createNotFoundException();
        $file = null;
        foreach ($recording->getFilesFor($student) as $candidate) {
            if ($candidate->getId() === $fileId) {
                $file = $candidate;
            }
        }

        if (null === $file) {
            throw $this->createNotFoundException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        return $this->json(['percent' => $listenTracker->register($file, $student, (int) ($payload['percent'] ?? 0))]);
    }

    /**
     * The files the student has to listen to, with what has already been heard of each: the common
     * ones and their own, never a classmate's individual file. Empty for anything but a Listening
     * assignment.
     *
     * The playback address travels with the row, unlike on the web where it is fetched on first
     * play: the sheet is loaded per assignment on mobile, so it only ever carries this student's
     * own files anyway.
     *
     * @return list<array<string, mixed>>
     */
    private function formatAudioFiles(Assignment $assignment, User $student, AudioListenTracker $listenTracker, AudioUploadService $uploadService): array
    {
        $recording = $assignment->getAudioRecording();

        if (null === $recording) {
            return [];
        }

        $percents = $listenTracker->progressPercents($recording, $student);

        return array_map(static fn (AudioRecordingFile $file): array => [
            'id' => $file->getId(),
            'name' => $file->getOriginalName(),
            'duration' => $file->getFormattedDuration(),
            'percent' => $percents[(int) $file->getId()] ?? 0,
            'url' => $uploadService->playbackUrl($file->getStorageKey()),
        ], $recording->getFilesFor($student));
    }

    /**
     * The teacher's own travaux (4d), split by the "En cours / Terminés" pills: en cours = the
     * deadline is still ahead, terminés = it has passed. Consultation only - the progress bar and
     * its counter are the whole point of the screen.
     */
    #[IsGranted('ROLE_TEACHER')]
    #[Route(path: '/api/teacher-work', name: 'api_teacher_work', methods: ['GET'])]
    public function teacherWork(
        Request $request,
        ProgramRepository $programRepository,
        AssignmentRepository $assignmentRepository,
        AssignmentProgressSummarizer $summarizer,
    ): JsonResponse {
        $teacher = $this->currentUser();
        $now = new \DateTimeImmutable();
        $finished = 'finished' === $request->query->get('scope');

        $programs = array_values(array_filter(
            $programRepository->findAllForTeacher($teacher),
            static fn (Program $program): bool => $program->isAssignmentManagementEnabled(),
        ));

        $assignments = array_values(array_filter(
            $assignmentRepository->findForPrograms($programs, $teacher),
            static fn (Assignment $assignment): bool => ($assignment->getDueDate() < $now) === $finished,
        ));

        usort($assignments, static fn (Assignment $a, Assignment $b): int => $finished
            ? $b->getDueDate() <=> $a->getDueDate()
            : $a->getDueDate() <=> $b->getDueDate());

        $progress = $summarizer->summarize($assignments, $now);

        return $this->json([
            'programs' => array_map(static fn (Program $program): array => [
                'id' => $program->getId(),
                'name' => $program->getDisplayShortName(),
            ], $programs),
            'items' => array_map(
                fn (Assignment $assignment): array => $this->formatTeacherItem($assignment, $progress[(int) $assignment->getId()] ?? null),
                $assignments,
            ),
        ]);
    }

    /**
     * One row of the student's list. "action" is what the row's right-hand side offers: a quiz and
     * a reading are actionable on mobile, everything else opens the consultation sheet - a deposit
     * included, since it can only be answered on the web (principe 3).
     *
     * @return array<string, mixed>
     */
    private function formatStudentItem(StudentWorkItem $item): array
    {
        $assignment = $item->assignment;
        $quizInstance = $assignment->getQuizInstance();
        $readingUrl = $this->readingUrlOf($assignment);

        return [
            'id' => $assignment->getId(),
            'title' => $assignment->getTitle(),
            'state' => $item->state->value,
            'dueDate' => $item->dueDate->format(\DateTimeInterface::ATOM),
            'subject' => $this->subjectOf($assignment),
            'subjectId' => $assignment->getTopic()?->getId(),
            'teacher' => $assignment->getCreatedBy()?->getDisplayName() ?? $assignment->getCreatedBy()?->getUsername(),
            'nature' => $assignment->getNature()->value,
            'action' => match (true) {
                null !== $quizInstance => 'quiz',
                // A listening is fully playable on the phone - it is even where it makes most sense -
                // so the row opens the sheet on its player rather than merely showing the brief.
                null !== $assignment->getAudioRecording() => 'listen',
                AssignmentNature::ToRead === $assignment->getNature() => 'read',
                default => 'open',
            },
            'quizInstanceId' => $quizInstance?->getId(),
            'questionCount' => $quizInstance?->getQuestionCount(),
            // Shown next to the question count when the teacher set a target ("objectif 70 %") -
            // there is no per-instance attempt limit in the model, so the row never claims one.
            'minimumScorePercent' => null !== $assignment->getMinimumScorePercent()
                ? (float) $assignment->getMinimumScorePercent()
                : null,
            'readingUrl' => $readingUrl,
            'expectationCount' => \count($item->expectations),
        ];
    }

    /**
     * One line of "Dépôts demandés". A handed-in deposit shows its file and the day it was handed
     * in; one still awaited shows the constraints the web brief spells out - format, accepted
     * extensions, size cap - and its own deadline, plus the "Dépôt sur le web" pill the mobile
     * sheet puts where the web has its "Déposer" button.
     *
     * @return array<string, mixed>
     */
    private function formatExpectation(Assignment $assignment, StudentWorkExpectation $expectation): array
    {
        $submission = $expectation->submission;
        $files = $submission?->getFiles()->toArray() ?? [];
        $file = [] === $files ? null : end($files);

        $constraints = [];
        if (null !== $expectation->production) {
            $constraints[] = $this->translator->trans($expectation->production->getFormat()->labelKey());
        }
        if ([] !== $assignment->getAcceptedFormats()) {
            $constraints[] = implode(' / ', array_map(strtoupper(...), $assignment->getAcceptedFormats()));
        }
        $constraints[] = $this->translator->trans('studentWorkMaxSizeLabel');

        return [
            'name' => $expectation->production?->getName()
                ?? $this->translator->trans('studentWorkSingleDepositLabel'),
            'submitted' => $expectation->isSubmitted(),
            'fileName' => $file?->getOriginalFilename(),
            'submittedAt' => $submission?->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            'dueDate' => $expectation->dueDate?->format(\DateTimeInterface::ATOM),
            'constraints' => implode(' · ', $constraints),
        ];
    }

    /** @return array{label: string, url: ?string, kind: string} */
    private function formatAttachment(AssignmentAttachment $attachment, FileUploadService $fileUploadService): array
    {
        $extension = strtoupper(pathinfo($attachment->getLabel(), \PATHINFO_EXTENSION));

        return [
            'label' => $attachment->getLabel(),
            'url' => $attachment->isLink()
                ? $attachment->getUrl()
                : ($attachment->getStorageKey() ? $fileUploadService->url($attachment->getStorageKey()) : null),
            // Drawn in the sheet's 32px tile - "LIEN" for a link, the extension otherwise.
            'kind' => $attachment->isLink() ? 'LIEN' : substr('' === $extension ? 'FICHIER' : $extension, 0, 4),
        ];
    }

    /**
     * A reading is "Lire"-able on mobile only when the teacher attached a link to follow; a
     * reading built out of an uploaded file is opened from the sheet like any other attachment.
     */
    private function readingUrlOf(Assignment $assignment): ?string
    {
        if (AssignmentNature::ToRead !== $assignment->getNature()) {
            return null;
        }

        foreach ($assignment->getAttachments() as $attachment) {
            if ($attachment->isLink()) {
                return $attachment->getUrl();
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function formatTeacherItem(Assignment $assignment, ?array $progress): array
    {
        $done = (int) ($progress['params']['%done%'] ?? 0);
        $total = (int) ($progress['params']['%total%'] ?? 0);
        $missing = isset($progress['params']['%missing%']) ? (int) $progress['params']['%missing%'] : null;

        return [
            'id' => $assignment->getId(),
            'title' => $assignment->getTitle(),
            'programName' => $assignment->getProgram()?->getDisplayShortName(),
            'programId' => $assignment->getProgram()?->getId(),
            'nature' => $assignment->getNature()->value,
            'natureLabel' => $this->translator->trans($assignment->getNature()->labelKey()),
            'dueDate' => $assignment->getDueDate()?->format(\DateTimeInterface::ATOM),
            // The mockup's bar and counter: how many answered out of the audience, and - once the
            // deadline is past - how many are still missing, which is what turns the bar red.
            'progress' => [
                'done' => $done,
                'total' => $total > 0 ? $total : null,
                'missing' => $missing,
                'alert' => (bool) ($progress['alert'] ?? false),
                'hidden' => (bool) ($progress['muted'] ?? false),
            ],
        ];
    }

    /**
     * The "Toutes les matières" filter of 4b, built from what is actually listed - a topic-less
     * travail is filed under its formation instead, as on the web row.
     *
     * @param list<StudentWorkItem> $items
     *
     * @return list<array{id: ?int, name: string}>
     */
    private function subjectsOf(array $items): array
    {
        $subjects = [];
        foreach ($items as $item) {
            $topic = $item->assignment->getTopic();
            if (null !== $topic) {
                $subjects[(int) $topic->getId()] = ['id' => $topic->getId(), 'name' => $topic->getName()];
            }
        }

        usort($subjects, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return array_values($subjects);
    }

    private function subjectOf(Assignment $assignment): ?string
    {
        return $assignment->getTopic()?->getName() ?? $assignment->getProgram()?->getDisplayShortName();
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
