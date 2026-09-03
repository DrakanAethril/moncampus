<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Assignment;
use App\Entity\AssignmentAttachment;
use App\Entity\AudioRecording;
use App\Entity\FileLibraryNode;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\VideoResource;
use App\Enum\AssignmentAttachmentSourceType;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\Feature;
use App\Enum\LessonLogSection;
use App\Form\AssignmentWizardType;
use App\Repository\AssignmentRepository;
use App\Repository\AudioRecordingRepository;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\GroupBatchRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Repository\UserRepository;
use App\Repository\VideoResourceRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\FileLibraryVoter;
use App\Service\AssignmentAudienceResolver;
use App\Service\AssignmentFollowUpBoard;
use App\Service\AssignmentNatureFields;
use App\Service\AssignmentNatureRequirements;
use App\Service\AssignmentProgressSummarizer;
use App\Service\AssignmentWizardContext;
use App\Service\FileLibraryWorkFactory;
use App\Service\FileUploadService;
use App\Service\FormValue;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Assignments as the teacher sees them, all classes together (design_handoff_creation_travail):
 * the 2b list and the 2a creation wizard.
 *
 * Deliberately outside the /programs/{id}/… tree: a teacher works across several classes and the
 * mockup gives them a single page. The per-program screens (ProgramAssignmentController, on the
 * settings side) stay in place and serve another need - staff administering a program's
 * assignments.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::StudentWork)]
class AssignmentController extends AbstractController
{
    private const string ATTACHMENT_UPLOAD_PREFIX = 'assignment-attachments/';

    // The states offered by the list's « Tous les états » filter (2b).
    private const string STATE_OVERDUE = 'overdue';
    private const string STATE_IMMINENT = 'imminent';
    private const string STATE_UPCOMING = 'upcoming';
    private const string STATE_HIDDEN = 'hidden';

    // « Imminent »: the deadline falls within two days. Anything beyond is simply upcoming,
    // anything past is overdue.
    private const int IMMINENT_DAYS = 2;

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly TranslatorInterface $translator,
        private readonly AudioRecordingRepository $audioRecordingRepository,
        private readonly VideoResourceRepository $videoResourceRepository,
        private readonly AssignmentNatureRequirements $natureRequirements,
        private readonly AssignmentNatureFields $natureFields,
        private readonly FileLibraryNodeRepository $libraryNodes,
        private readonly FileLibraryWorkFactory $workFactory,
    ) {
    }

    #[Route(path: '/assignments', name: 'app_assignments', methods: ['GET'])]
    public function list(Request $request, ProgramRepository $programRepository, AssignmentRepository $assignmentRepository, AssignmentAudienceResolver $audienceResolver, AssignmentProgressSummarizer $summarizer): Response
    {
        $programs = $this->teachingPrograms($programRepository);
        $now = new \DateTimeImmutable();

        $assignments = $assignmentRepository->findForPrograms($programs, $this->accessChecker->isStaff() ? null : $this->currentUser());

        // Read through QueryValue, not the InputBag's own getInt(): every one of these four filters
        // offers a blank "Toutes/Tous" option, so the toolbar submits `?classe=&type=&etat=` as a
        // matter of course, and getInt() answers a 400 to the empty string rather than treating it
        // as "not provided".
        $search = QueryValue::trimmed($request, 'q');
        $programFilter = QueryValue::int($request, 'classe');
        $natureFilter = AssignmentNature::tryFrom(QueryValue::string($request, 'type'));
        $stateFilter = QueryValue::string($request, 'etat');

        $rows = [];
        foreach ($assignments as $assignment) {
            $state = $this->stateOf($assignment, $now);

            if ('' !== $search && !$this->matchesSearch($assignment, $search)) {
                continue;
            }
            if (0 !== $programFilter && $assignment->getProgram()?->getId() !== $programFilter) {
                continue;
            }
            if (null !== $natureFilter && $assignment->getNature() !== $natureFilter) {
                continue;
            }
            if ('' !== $stateFilter && $state !== $stateFilter) {
                continue;
            }

            $rows[] = [
                'assignment' => $assignment,
                'state' => $state,
                'details' => $this->audienceDetails($assignment, $audienceResolver),
            ];
        }

        return $this->render('assignment/list.html.twig', [
            'rows' => $rows,
            'progress' => $summarizer->summarize(array_column($rows, 'assignment'), $now),
            'programs' => $programs,
            'natures' => AssignmentNature::forLessonLog(),
            'states' => [self::STATE_OVERDUE, self::STATE_IMMINENT, self::STATE_UPCOMING, self::STATE_HIDDEN],
            'filters' => ['q' => $search, 'classe' => $programFilter, 'type' => null !== $natureFilter ? $natureFilter->value : '', 'etat' => $stateFilter],
        ]);
    }

    /**
     * The « Nouveau travail » wizard. A single entry point for the three mountings (full page,
     * modal, panel) and for every calling context: what changes is described by the request - the
     * class one comes from, the séance one comes from - and resolves into an
     * AssignmentWizardContext, shared by the template and the form.
     *
     * GET draws, POST publishes. Nothing is written in between: the four steps live in the page,
     * not in the database, and a server round trip only happens on publication.
     */
    #[Route(path: '/assignments/new', name: 'app_assignment_new', methods: ['GET', 'POST'])]
    public function wizard(
        Request $request,
        EntityManagerInterface $entityManager,
        ProgramRepository $programRepository,
        LessonSessionRepository $lessonSessionRepository,
        TopicRepository $topicRepository,
        UserRepository $userRepository,
        GroupBatchRepository $groupBatchRepository,
        UploadIntake $uploadIntake,
        FileUploadService $fileUploadService,
    ): Response {
        return $this->runWizard($request, null, $entityManager, $programRepository, $lessonSessionRepository, $topicRepository, $userRepository, $groupBatchRepository, $uploadIntake, $fileUploadService);
    }

    /**
     * Edit an assignment already given. The same wizard, opened on an existing assignment: it is
     * the only screen of an assignment, whether it is being created or revisited, ever since the
     * cahier de texte dropped its own modal.
     *
     * One single thing cannot be changed there: the class. Moving a published assignment from one
     * class to another would silently change who owes it, and what became of the submissions
     * already made.
     */
    #[Route(path: '/assignments/{id}/edit', name: 'app_assignment_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        AssignmentRepository $assignmentRepository,
        ProgramRepository $programRepository,
        LessonSessionRepository $lessonSessionRepository,
        TopicRepository $topicRepository,
        UserRepository $userRepository,
        GroupBatchRepository $groupBatchRepository,
        UploadIntake $uploadIntake,
        FileUploadService $fileUploadService,
    ): Response {
        $assignment = $this->findOrNotFound($id, $assignmentRepository, $programRepository);

        return $this->runWizard($request, $assignment, $entityManager, $programRepository, $lessonSessionRepository, $topicRepository, $userRepository, $groupBatchRepository, $uploadIntake, $fileUploadService);
    }

    private function runWizard(
        Request $request,
        ?Assignment $existing,
        EntityManagerInterface $entityManager,
        ProgramRepository $programRepository,
        LessonSessionRepository $lessonSessionRepository,
        TopicRepository $topicRepository,
        UserRepository $userRepository,
        GroupBatchRepository $groupBatchRepository,
        UploadIntake $uploadIntake,
        FileUploadService $fileUploadService,
    ): Response {
        $isEdit = null !== $existing;
        $mode = $this->mountMode($request);

        // On edit, the only class offered is the assignment's own: the wizard keeps its step 1 -
        // the recipients WITHIN the class do stay editable - but the assignment does not change
        // class.
        $programs = $isEdit ? [$existing->getProgram()] : $this->teachingPrograms($programRepository);

        if ([] === $programs) {
            throw $this->createAccessDeniedException();
        }

        $context = $isEdit
            ? AssignmentWizardContext::forAssignment($existing, $this->returnUrlFor($existing->getLessonSession()), $mode)
            : $this->resolveContext($request, $programs, $lessonSessionRepository, $mode);
        $assignment = $existing ?? $this->prefilledAssignment($context, $programs);

        $form = $this->createForm(AssignmentWizardType::class, $assignment, [
            'programs' => $programs,
            'teacher' => $this->currentUser(),
            'teacher_topics_only' => $this->accessChecker->isStaff() ? null : $this->currentUser(),
            'visibility' => $this->visibilityOf($assignment),
            'natures' => match (true) {
                null !== $context->audioRecording => [AssignmentNature::Listening],
                null !== $context->videoResource => [AssignmentNature::Watching],
                default => AssignmentNature::forLessonLog(),
            },
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateNatureRequirements($form);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Assignment $saved */
            $saved = $form->getData();

            $this->applyAudience($saved, $request, $userRepository);
            $this->natureFields->apply($saved);
            $this->applyVisibility($saved, $form);
            $this->removeDroppedAttachments($saved, $request, $fileUploadService);
            $this->applyAttachments($saved, $form, $uploadIntake);

            if ($isEdit) {
                $saved->setLastUpdatedBy($this->currentUser());
                $saved->setLastUpdatedDate(new \DateTimeImmutable());
            } else {
                $this->applyAutomaticAttachment($saved, $context, $topicRepository);
                $saved->setCreatedBy($this->currentUser());
                $entityManager->persist($saved);

                // The back-link, which moves the recording to the "Travail créé" status and opens its
                // statistics screen. Same on the video side.
                $context->audioRecording?->setAssignment($saved);
                $context->videoResource?->setAssignment($saved);

                if (null !== $context->libraryNode) {
                    // The file is a **link**: the row carries the node's own storage key plus a
                    // foreign key back to it.
                    $this->workFactory->attach($saved, $context->libraryNode);

                    // And a video opens the Vidéos tool's back door: the resource and its file are
                    // created here, referencing the same object, so the cue-point editor and the
                    // statistics screen are reached from the work exactly as they always were.
                    if (AssignmentNature::Watching === $saved->getNature()) {
                        $saved->setVideoResource($this->workFactory->createVideoResource($saved, $context->libraryNode, $this->currentUser()));
                    }
                }
            }

            $entityManager->flush();

            $this->warnAboutQuizWindow($saved);

            // An edit, or a creation started from a séance, goes back where it came from: the
            // séance shows the assignment in its part, and that is the confirmation. The 2a
            // confirmation screen closes a creation that had no screen of origin.
            if ($isEdit || null !== $context->lessonSession) {
                $this->addFlash('success', $isEdit ? 'assignmentSavedFlashMessage' : 'assignmentPublishedFlashMessage');

                return $this->redirect($context->returnUrl);
            }

            return $this->redirectToRoute('app_assignment_published', ['id' => $saved->getId()]);
        }

        // The modal and panel mountings are a fragment: they carry neither header nor stylesheet,
        // and only make sense loaded into the Turbo frame of an already displayed screen. Opened
        // directly, the same address renders the full page - a shared or reopened address must not
        // yield a bare screen.
        $embedded = $context->isEmbedded() && $request->headers->has('Turbo-Frame');

        return $this->render($embedded ? 'assignment/_wizard_embedded.html.twig' : 'assignment/new.html.twig', [
            'form' => $form,
            'context' => $context,
            'isEdit' => $isEdit,
            'assignment' => $isEdit ? $existing : null,
            // The form posts to ITS own address and not to the page's: mounted in a Turbo frame, an
            // empty action would target the screen hosting it.
            'wizardAction' => $request->getRequestUri(),
            'programs' => $programs,
            'presets' => $this->dueDatePresets($context),
            'studentsByProgram' => $this->studentsByProgram($programs),
            'groupBatchMembers' => $this->groupBatchMembers($programs, $groupBatchRepository),
        ]);
    }

    /**
     * A quiz's own window and the travail's échéance are two settings, and nothing keeps them in
     * step: a quiz that closes first leaves the work standing on the student's list with no way to
     * answer it. Said once, at the moment the pair is decided, and never as an error - a teacher may
     * well want the quiz shut before the work is due.
     *
     * The reverse case is silent on purpose: a quiz still open after the deadline takes nothing away.
     */
    private function warnAboutQuizWindow(Assignment $assignment): void
    {
        $closesAt = $assignment->getQuizInstance()?->getClosesAt();

        if (null !== $closesAt && $closesAt < $assignment->getDueDate()) {
            $this->addFlash('warning', 'assignmentQuizClosesBeforeDueDateFlashMessage');
        }
    }

    /** The publication confirmation screen - a page in its own right, reached by redirect. */
    #[Route(path: '/assignments/{id}/published', name: 'app_assignment_published', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function published(int $id, AssignmentRepository $assignmentRepository, ProgramRepository $programRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        $assignment = $this->findOrNotFound($id, $assignmentRepository, $programRepository);

        return $this->render('assignment/published.html.twig', [
            'assignment' => $assignment,
            'details' => $this->audienceDetails($assignment, $audienceResolver),
        ]);
    }

    /**
     * « Consulter »: the follow-up of an assignment's submissions. The full screen (reminders,
     * corrections) is outside this handoff's scope - what is laid down here is the navigation and
     * the state of the submissions, student by student.
     */
    #[Route(path: '/assignments/{id}', name: 'app_assignment_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, AssignmentRepository $assignmentRepository, ProgramRepository $programRepository, AssignmentAudienceResolver $audienceResolver, AssignmentProgressSummarizer $summarizer, AssignmentFollowUpBoard $followUpBoard): Response
    {
        $assignment = $this->findOrNotFound($id, $assignmentRepository, $programRepository);

        $audience = $audienceResolver->resolveAudience($assignment);
        usort($audience, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        // Not the deposits: whatever this nature accepts as proof. Six natures out of eleven never
        // produce a deposit, and reading only those is what made this table announce « Non rendu »
        // to a whole class under a progress line saying they had answered.
        return $this->render('assignment/show.html.twig', [
            'assignment' => $assignment,
            'rows' => $followUpBoard->rows($assignment, $audience),
            'details' => $this->audienceDetails($assignment, $audienceResolver),
            'progress' => $summarizer->summarize([$assignment])[$assignment->getId()] ?? null,
        ]);
    }

    /**
     * The classes the user may give work to: the ones they teach, or every one they can see for
     * staff. Assignment management is switched off program by program, and a program that has
     * closed it appears nowhere here.
     *
     * @return list<Program>
     */
    private function teachingPrograms(ProgramRepository $repository): array
    {
        $viewer = $this->currentUser();
        $programs = $this->accessChecker->isStaff()
            ? $repository->findActiveForNav($viewer)
            : $repository->findAllForTeacher($viewer);

        return array_values(array_filter($programs, static fn (Program $program): bool => $program->isAssignmentManagementEnabled()));
    }

    private function mountMode(Request $request): string
    {
        $embed = QueryValue::string($request, 'embed');

        return \in_array($embed, [AssignmentWizardContext::MODE_MODAL, AssignmentWizardContext::MODE_PANEL], true)
            ? $embed
            : AssignmentWizardContext::MODE_PAGE;
    }

    /**
     * Where « Annuler » and saving lead back to: the séance when the assignment hangs off one, the
     * assignment list otherwise. One always returns to the screen this assignment was spoken of on.
     */
    private function returnUrlFor(?LessonSession $session): string
    {
        return null !== $session
            ? $this->generateUrl('app_program_timetable_session_log', ['id' => $session->getProgram()->getId(), 'sessionId' => $session->getId()])
            : $this->generateUrl('app_assignments');
    }

    /** @param list<Program> $programs */
    private function resolveContext(Request $request, array $programs, LessonSessionRepository $lessonSessionRepository, string $mode): AssignmentWizardContext
    {
        $sessionId = QueryValue::int($request, 'seance');
        if (0 !== $sessionId) {
            $session = $lessonSessionRepository->find($sessionId);

            // The attachment is never asked for: it comes from the entry point. That entry point
            // still has to be a séance of a class one teaches.
            if ($session instanceof LessonSession && $this->isAmong($session->getProgram(), $programs)) {
                return AssignmentWizardContext::forLessonSession(
                    $session,
                    LessonLogSection::tryFrom(QueryValue::string($request, 'temps')) ?? LessonLogSection::After,
                    $this->returnUrlFor($session),
                    $mode,
                );
            }
        }

        // From an audio recording: the class, the options and the nature all follow from it, and
        // "Annuler" goes back to the recording rather than to the list.
        $recordingId = QueryValue::int($request, 'recording');
        if (0 !== $recordingId) {
            $recording = $this->audioRecordingRepository->find($recordingId);

            if ($recording instanceof AudioRecording && $this->isAmong($recording->getProgram(), $programs)) {
                return AssignmentWizardContext::forAudioRecording(
                    $recording,
                    $this->generateUrl('app_audio_recording_files', ['recordingId' => $recording->getId()]),
                    $mode,
                );
            }
        }

        // From a video resource, the counterpart of the recording just above.
        $videoId = QueryValue::int($request, 'video');
        if (0 !== $videoId) {
            $resource = $this->videoResourceRepository->find($videoId);

            if ($resource instanceof VideoResource && $this->isAmong($resource->getProgram(), $programs)) {
                return AssignmentWizardContext::forVideoResource(
                    $resource,
                    $this->generateUrl('app_video_resource_files', ['resourceId' => $resource->getId()]),
                    $mode,
                );
            }
        }

        // From a file of the teacher's own library. The Voter is what makes "their own" true: a node
        // id in a query string is not a permission.
        $libraryNodeId = QueryValue::int($request, 'libraryNode');
        if (0 !== $libraryNodeId) {
            $node = $this->libraryNodes->find($libraryNodeId);

            if ($node instanceof FileLibraryNode && $node->isFile() && !$node->isDeleted() && $this->isGranted(FileLibraryVoter::LINK, $node)) {
                return AssignmentWizardContext::forLibraryNode(
                    $node,
                    $this->generateUrl('app_file_library'),
                    $mode,
                );
            }
        }

        $listUrl = $this->generateUrl('app_assignments');
        $programId = QueryValue::int($request, 'classe');
        foreach ($programs as $program) {
            if ($program->getId() === $programId) {
                return AssignmentWizardContext::forProgram($program, $listUrl, $mode);
            }
        }

        return AssignmentWizardContext::generic($listUrl, $mode);
    }

    /** The three « Visibilité pour les étudiants » cards are read off the single stored date. */
    private function visibilityOf(Assignment $assignment): string
    {
        return match (true) {
            null === $assignment->getVisibleAt() => AssignmentWizardType::VISIBILITY_HIDDEN,
            $assignment->getVisibleAt() > new \DateTimeImmutable() => AssignmentWizardType::VISIBILITY_SCHEDULED,
            default => AssignmentWizardType::VISIBILITY_NOW,
        };
    }

    /**
     * The supports detached on edit. Ticked on screen, they only go on saving - nothing is written
     * before, on edit as on creation - and the stored file leaves with its row, failing which it
     * would be left alone on S3.
     */
    private function removeDroppedAttachments(Assignment $assignment, Request $request, FileUploadService $fileUploadService): void
    {
        $droppedIds = array_map('intval', PostValue::all($request, 'removed_attachments'));

        foreach ($assignment->getAttachments()->toArray() as $attachment) {
            if (!\in_array($attachment->getId(), $droppedIds, true)) {
                continue;
            }

            if (null !== $attachment->getStorageKey()) {
                $fileUploadService->delete($attachment->getStorageKey());
            }

            $assignment->getAttachments()->removeElement($attachment);
        }
    }

    /**
     * The assignment as it stands when the wizard opens: what the entry point already knows.
     * Nothing is persisted - this is a form object, not a draft.
     *
     * @param list<Program> $programs
     */
    private function prefilledAssignment(AssignmentWizardContext $context, array $programs): Assignment
    {
        $assignment = new Assignment($context->program ?? $programs[0]);

        // With no known class, step 1 must open empty and block - the first class of the list is
        // only there to give the object a carrier.
        if (null === $context->program) {
            $assignment->setProgram(null);
        }

        $assignment->setAudienceType($context->audienceType);

        // An assignment born of a recording is a listening and nothing else, one born of a video is
        // a watching and nothing else: the nature is not offered (see AssignmentWizardType, which
        // then holds only that one) and the media's name makes a far more useful title than an empty
        // field.
        $assignment->setNature(match (true) {
            null !== $context->audioRecording => AssignmentNature::Listening,
            null !== $context->videoResource => AssignmentNature::Watching,
            // A file of the library says what kind of work it is: a video is a watching, an audio
            // file a listening, anything else a to-submit.
            null !== $context->libraryNode => $this->workFactory->natureFor($context->libraryNode),
            default => AssignmentNature::ToSubmit,
        });
        $assignment->setAudioRecording($context->audioRecording);
        $assignment->setVideoResource($context->videoResource);
        $assignment->setTitle(
            $context->audioRecording?->getName()
            ?? $context->videoResource?->getName()
            // The file's name without its extension, which is what the teacher would have typed.
            ?? (null === $context->libraryNode ? null : $this->workFactory->titleFor($context->libraryNode)),
        );
        $assignment->setDueDate($this->defaultDueDate($context));
        $assignment->setLessonSession($context->lessonSession);
        $assignment->setLessonLogSection($context->lessonLogSection);
        $assignment->setTopic($context->topic);

        foreach ($context->options as $option) {
            $assignment->addOption($option);
        }

        return $assignment;
    }

    // The mockup's « Prochaine séance »: the following week at the séance's time when coming from a
    // lesson, tomorrow morning otherwise.
    private function defaultDueDate(AssignmentWizardContext $context): \DateTimeImmutable
    {
        $start = $context->lessonSession?->getStartAt();

        return null !== $start
            ? $start->modify('+7 days')
            : (new \DateTimeImmutable('tomorrow'))->setTime(8, 0);
    }

    /**
     * The two deadline shortcuts of step 4 - « Prochaine séance » and « Fin de semaine ». They are
     * computed here, where the originating séance is known; the screen only offers them.
     *
     * @return array{next: \DateTimeImmutable, week: \DateTimeImmutable}
     */
    private function dueDatePresets(AssignmentWizardContext $context): array
    {
        return [
            'next' => $this->defaultDueDate($context),
            'week' => (new \DateTimeImmutable('next friday'))->setTime(17, 0),
        ];
    }

    /**
     * The recipients named one by one, ticked in the step 1 list. Same convention as
     * ProgramAssignmentController: raw checkboxes rather than a form field, the list depending on
     * the class chosen in the same screen.
     */
    private function applyAudience(Assignment $assignment, Request $request, UserRepository $userRepository): void
    {
        $program = $assignment->getProgram();

        // An option ticked for another class has no business here: the screen hides them, the
        // server strips them.
        foreach ($assignment->getOptions()->toArray() as $option) {
            if (AssignmentAudienceType::Option !== $assignment->getAudienceType() || !$this->optionBelongsTo($option, $program)) {
                $assignment->removeOption($option);
            }
        }

        foreach ($assignment->getManualRecipients()->toArray() as $recipient) {
            $assignment->removeManualRecipient($recipient);
        }

        if (AssignmentAudienceType::Manual === $assignment->getAudienceType() && null !== $program) {
            $submittedIds = array_map('intval', PostValue::all($request, 'manual_recipients'));
            foreach ($userRepository->findByIdsForProgram($program, $submittedIds) as $student) {
                $assignment->addManualRecipient($student);
            }
        }

        if (AssignmentAudienceType::GroupBatch !== $assignment->getAudienceType() || $assignment->getGroupBatch()?->getProgram()->getId() !== $program?->getId()) {
            $assignment->setGroupBatch(null);
        }
    }

    /** The three « Visibilité pour les étudiants » cards fold into a single date. */
    private function applyVisibility(Assignment $assignment, \Symfony\Component\Form\FormInterface $form): void
    {
        $assignment->setVisibleAt(match ($form->get('visibility')->getData()) {
            AssignmentWizardType::VISIBILITY_NOW => new \DateTimeImmutable(),
            AssignmentWizardType::VISIBILITY_SCHEDULED => $form->get('visibleAt')->getData() ?? new \DateTimeImmutable(),
            default => null,
        });
    }

    /**
     * The supports travel with the form and only become rows here: the assignment did not exist
     * before, so there was nothing to attach them to.
     */
    private function applyAttachments(Assignment $assignment, \Symfony\Component\Form\FormInterface $form, UploadIntake $uploadIntake): void
    {
        /** @var list<StagedUpload> $files */
        $files = $form->get('attachmentFiles')->getData() ?? [];

        foreach ($files as $file) {
            $extension = UploadIntake::extension($file);
            $key = $uploadIntake->store(
                $file,
                self::ATTACHMENT_UPLOAD_PREFIX,
                sprintf('%d-%s.%s', time(), bin2hex(random_bytes(4)), $extension),
            );

            $node = UploadIntake::libraryNodeOf($file);
            // Library or Upload: the row reads the same either way - it carries its own storage key -
            // and what the third case adds is *where it came from*, which is what the usage panel and
            // the deletion modal both need to know.
            (new AssignmentAttachment($assignment, UploadIntake::originalName($file), null === $node ? AssignmentAttachmentSourceType::Upload : AssignmentAttachmentSourceType::Library))
                ->setStorageKey($key)
                ->setLibraryNode($node);
        }

        foreach (preg_split('/\R/', FormValue::string($form, 'attachmentLinks')) ?: [] as $line) {
            $url = trim($line);

            if ('' === $url) {
                continue;
            }

            (new AssignmentAttachment($assignment, $url, AssignmentAttachmentSourceType::Link))->setUrl($url);
        }
    }

    /**
     * The automatic attachment, never asked for: the séance and its part when coming from the
     * cahier de texte, and the matière - the séance's own, or the single matière the teacher covers
     * in the chosen class. Several possible matières and no séance: nothing is invented, the
     * assignment will be read with no matière mentioned.
     */
    private function applyAutomaticAttachment(Assignment $assignment, AssignmentWizardContext $context, TopicRepository $topicRepository): void
    {
        $assignment->setLessonSession($context->lessonSession);
        $assignment->setLessonLogSection($context->lessonLogSection);

        if (null !== $context->lessonSession?->getTopic()) {
            $assignment->setTopic($context->lessonSession->getTopic());

            return;
        }

        $program = $assignment->getProgram();
        $topics = null === $program ? [] : $topicRepository->findBy([
            'program' => $program,
            'teacher' => $this->currentUser(),
            'inactiveDate' => null,
        ]);

        $assignment->setTopic(1 === \count($topics) ? $topics[0] : null);
    }

    /**
     * The only two rules the type imposes: a quiz to run for a Quiz assignment, an evaluation to
     * estimate for a self-assessment. Without them the student would have nothing to open. They are
     * checked here and not in the form type, which does not see the other fields at the time it is
     * built.
     */
    private function validateNatureRequirements(\Symfony\Component\Form\FormInterface $form): void
    {
        /** @var Assignment $assignment */
        $assignment = $form->getData();

        foreach ($this->natureRequirements->missing($assignment) as $field => $message) {
            $form->get($field)->addError(new \Symfony\Component\Form\FormError($this->translator->trans($message)));
        }
    }

    /**
     * The sub-line of an assignment in the list: what tells it apart from another of the same type
     * - who it targets, and what is particular about it.
     *
     * @return array{audience: string, particularities: list<string>}
     */
    private function audienceDetails(Assignment $assignment, AssignmentAudienceResolver $audienceResolver): array
    {
        $audience = match ($assignment->getAudienceType()) {
            AssignmentAudienceType::Option => implode(', ', array_map(
                static fn (Option $option): string => $option->getName(),
                $assignment->getOptions()->toArray(),
            )),
            AssignmentAudienceType::Manual => $this->translator->trans('assignmentAudienceSelectedStudentsLabel', ['%count%' => \count($assignment->getManualRecipients())]),
            AssignmentAudienceType::GroupBatch => $this->translator->trans('assignmentAudienceGroupBatchLabel', ['%name%' => $assignment->getGroupBatch()?->getName() ?? '']),
            default => $this->translator->trans('assignmentAudienceWholeClassLabel'),
        };

        $particularities = [];

        if ($assignment->hasMultipleDueDates()) {
            $particularities[] = $this->translator->trans('assignmentParticularityMultipleDueDatesLabel', ['%count%' => \count($assignment->getExpectedProductions())]);
        }

        if (AssignmentAudienceType::GroupBatch === $assignment->getAudienceType()) {
            $particularities[] = $this->translator->trans('assignmentParticularityGroupSubmissionLabel');
        }

        if (!$assignment->isMandatory()) {
            $particularities[] = $this->translator->trans('assignmentParticularityOptionalLabel');
        }

        return ['audience' => $audience, 'particularities' => $particularities];
    }

    // The state of an assignment as the Échéance column gives it to read: hidden, overdue, imminent
    // or upcoming - in that order, a hidden assignment having no deadline that still counts.
    private function stateOf(Assignment $assignment, \DateTimeImmutable $now): string
    {
        return match (true) {
            !$assignment->isVisibleFor($now) => self::STATE_HIDDEN,
            $assignment->getDueDate() < $now => self::STATE_OVERDUE,
            $assignment->getDueDate() <= $now->modify(sprintf('+%d days', self::IMMINENT_DAYS)) => self::STATE_IMMINENT,
            default => self::STATE_UPCOMING,
        };
    }

    private function matchesSearch(Assignment $assignment, string $search): bool
    {
        $haystack = mb_strtolower(implode(' ', [
            (string) $assignment->getTitle(),
            $assignment->getTopic()?->getName() ?? '',
            $assignment->getProgram()?->getDisplayShortName() ?? '',
        ]));

        return str_contains($haystack, mb_strtolower($search));
    }

    /**
     * The students of each candidate class, for the tick list of step 1 - every class at once, the
     * screen only displaying the chosen one.
     *
     * @param list<Program> $programs
     *
     * @return array<int, list<User>>
     */
    private function studentsByProgram(array $programs): array
    {
        $byProgram = [];

        foreach ($programs as $program) {
            $students = $program->getStudents()->toArray();
            usort($students, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));
            $byProgram[(int) $program->getId()] = $students;
        }

        return $byProgram;
    }

    /**
     * The make-up of the teacher's group batches, ready to display as summary chips (step 1,
     * « Par groupes » targeting). The batch's frozen identifiers are cross-checked against today's
     * class: a student who has left since no longer appears in their group.
     *
     * @param list<Program> $programs
     *
     * @return array<int, list<list<string>>> batch identifier => groups => member names
     */
    private function groupBatchMembers(array $programs, GroupBatchRepository $groupBatchRepository): array
    {
        $namesById = [];
        foreach ($this->studentsByProgram($programs) as $students) {
            foreach ($students as $student) {
                $namesById[(int) $student->getId()] = $student->getDisplayName() ?? $student->getUsername();
            }
        }

        $members = [];
        foreach ($groupBatchRepository->findAllForTeacherAndPrograms($this->currentUser(), $programs) as $batch) {
            $members[(int) $batch->getId()] = array_map(
                static fn (array $ids): array => array_values(array_filter(array_map(
                    static fn (int $id): ?string => $namesById[$id] ?? null,
                    $ids,
                ))),
                $batch->getGroups(),
            );
        }

        return $members;
    }

    /** @param list<Program> $programs */
    private function isAmong(?Program $program, array $programs): bool
    {
        foreach ($programs as $candidate) {
            if ($candidate->getId() === $program?->getId()) {
                return true;
            }
        }

        return false;
    }

    private function optionBelongsTo(Option $option, ?Program $program): bool
    {
        foreach ($option->getPrograms() as $candidate) {
            if ($candidate->getId() === $program?->getId()) {
                return true;
            }
        }

        return false;
    }

    private function findOrNotFound(int $id, AssignmentRepository $assignmentRepository, ProgramRepository $programRepository): Assignment
    {
        $assignment = $assignmentRepository->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isAmong($assignment->getProgram(), $this->teachingPrograms($programRepository))) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
