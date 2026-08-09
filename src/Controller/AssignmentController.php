<?php

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\AssignmentAttachment;
use App\Entity\AudioRecording;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentAttachmentSourceType;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\AssignmentSubmissionStatus;
use App\Enum\LessonLogSection;
use App\Enum\SelfAssessmentFeedback;
use App\Form\AssignmentWizardType;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\AudioRecordingRepository;
use App\Repository\GroupBatchRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Repository\UserRepository;
use App\Security\StructureAccessChecker;
use App\Service\AssignmentAudienceResolver;
use App\Service\AssignmentProgressSummarizer;
use App\Service\AssignmentWizardContext;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les travaux vus par l'enseignant, toutes classes confondues (design_handoff_creation_travail) :
 * la liste 2b et l'assistant de création 2a.
 *
 * Délibérément hors de l'arborescence /programs/{id}/… : un enseignant travaille sur plusieurs
 * classes et la maquette lui donne une page unique. Les écrans par formation
 * (ProgramAssignmentController, côté paramétrage) restent en place et servent un autre besoin -
 * l'administration des travaux d'une formation par le personnel.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class AssignmentController extends AbstractController
{
    private const string ATTACHMENT_UPLOAD_PREFIX = 'assignment-attachments/';

    // Les états proposés par le filtre « Tous les états » de la liste (2b).
    private const string STATE_OVERDUE = 'overdue';
    private const string STATE_IMMINENT = 'imminent';
    private const string STATE_UPCOMING = 'upcoming';
    private const string STATE_HIDDEN = 'hidden';

    // « Imminent » : l'échéance tombe dans les deux jours. Ce qui reste au-delà est simplement à
    // venir, ce qui est passé est échu.
    private const int IMMINENT_DAYS = 2;

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly TranslatorInterface $translator,
        private readonly AudioRecordingRepository $audioRecordingRepository,
    ) {
    }

    #[Route(path: '/assignments', name: 'app_assignments', methods: ['GET'])]
    public function list(Request $request, ProgramRepository $programRepository, AssignmentRepository $assignmentRepository, AssignmentAudienceResolver $audienceResolver, AssignmentProgressSummarizer $summarizer): Response
    {
        $programs = $this->teachingPrograms($programRepository);
        $now = new \DateTimeImmutable();

        $assignments = $assignmentRepository->findForPrograms($programs, $this->accessChecker->isStaff() ? null : $this->currentUser());

        $search = trim((string) $request->query->get('q', ''));
        $programFilter = $request->query->getInt('classe');
        $natureFilter = AssignmentNature::tryFrom((string) $request->query->get('type', ''));
        $stateFilter = (string) $request->query->get('etat', '');

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
     * L'assistant « Nouveau travail ». Un seul point d'entrée pour les trois montages (pleine page,
     * modale, panneau) et pour tous les contextes d'appel : ce qui change est décrit par la requête
     * - la classe d'où l'on vient, la séance d'où l'on vient - et se résout en un
     * AssignmentWizardContext, que le gabarit et le formulaire se partagent.
     *
     * GET dessine, POST publie. Rien n'est écrit entre les deux : les quatre étapes vivent dans la
     * page, pas en base, et un aller-retour serveur n'a lieu qu'à la publication.
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
        FileUploadService $fileUploadService,
    ): Response {
        return $this->runWizard($request, null, $entityManager, $programRepository, $lessonSessionRepository, $topicRepository, $userRepository, $groupBatchRepository, $fileUploadService);
    }

    /**
     * Modifier un travail déjà donné. Le même assistant, ouvert sur un travail existant : c'est le
     * seul écran d'un travail, qu'on le crée ou qu'on y revienne, depuis que le cahier de texte a
     * abandonné son modal propre.
     *
     * Une seule chose n'y est pas modifiable : la classe. Déplacer un travail publié d'une classe à
     * l'autre changerait sans le dire qui le doit, et ce que sont devenus les rendus déjà faits.
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
        FileUploadService $fileUploadService,
    ): Response {
        $assignment = $this->findOrNotFound($id, $assignmentRepository, $programRepository);

        return $this->runWizard($request, $assignment, $entityManager, $programRepository, $lessonSessionRepository, $topicRepository, $userRepository, $groupBatchRepository, $fileUploadService);
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
        FileUploadService $fileUploadService,
    ): Response {
        $isEdit = null !== $existing;
        $mode = $this->mountMode($request);

        // À la modification, la seule classe offerte est celle du travail : l'assistant garde son
        // étape 1 - les destinataires AU SEIN de la classe, eux, restent modifiables - mais le
        // travail ne change pas de classe.
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
            'natures' => null !== $context->audioRecording ? [AssignmentNature::Listening] : AssignmentNature::forLessonLog(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateNatureRequirements($form);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Assignment $saved */
            $saved = $form->getData();

            $this->applyAudience($saved, $request, $userRepository);
            $this->applyNatureFields($saved);
            $this->applyVisibility($saved, $form);
            $this->removeDroppedAttachments($saved, $request, $fileUploadService);
            $this->applyAttachments($saved, $form, $fileUploadService);

            if ($isEdit) {
                $saved->setLastUpdatedBy($this->currentUser());
                $saved->setLastUpdatedDate(new \DateTimeImmutable());
            } else {
                $this->applyAutomaticAttachment($saved, $context, $topicRepository);
                $saved->setCreatedBy($this->currentUser());
                $entityManager->persist($saved);

                // The back-link, which moves the recording to the "Travail créé" status and opens its
                // statistics screen.
                $context->audioRecording?->setAssignment($saved);
            }

            $entityManager->flush();

            // Une modification, ou une création faite depuis une séance, revient là d'où elle
            // vient : la séance affiche le travail dans son temps, c'est la confirmation. L'écran
            // de confirmation du 2a, lui, conclut une création qui n'avait pas d'écran d'origine.
            if ($isEdit || null !== $context->lessonSession) {
                $this->addFlash('success', $isEdit ? 'assignmentSavedFlashMessage' : 'assignmentPublishedFlashMessage');

                return $this->redirect($context->returnUrl);
            }

            return $this->redirectToRoute('app_assignment_published', ['id' => $saved->getId()]);
        }

        // Le montage en modale ou en panneau est un fragment : il n'a ni en-tête ni feuille de
        // style, et n'a de sens que chargé dans le cadre Turbo d'un écran déjà affiché. Ouverte
        // directement, la même adresse rend la pleine page - une adresse partagée ou rouverte ne
        // doit pas donner un écran nu.
        $embedded = $context->isEmbedded() && $request->headers->has('Turbo-Frame');

        return $this->render($embedded ? 'assignment/_wizard_embedded.html.twig' : 'assignment/new.html.twig', [
            'form' => $form,
            'context' => $context,
            'isEdit' => $isEdit,
            'assignment' => $isEdit ? $existing : null,
            // Le formulaire poste sur SA propre adresse et non sur celle de la page : monté dans un
            // cadre Turbo, une action vide viserait l'écran qui l'héberge.
            'wizardAction' => $request->getRequestUri(),
            'programs' => $programs,
            'presets' => $this->dueDatePresets($context),
            'studentsByProgram' => $this->studentsByProgram($programs),
            'groupBatchMembers' => $this->groupBatchMembers($programs, $groupBatchRepository),
        ]);
    }

    /** L'écran de confirmation de publication - une page à part entière, atteinte par redirection. */
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
     * « Consulter » : le suivi des rendus d'un travail. L'écran complet (relances, corrections) est
     * hors du périmètre de ce handoff - ce qui est posé ici est la navigation et l'état des rendus,
     * étudiant par étudiant.
     */
    #[Route(path: '/assignments/{id}', name: 'app_assignment_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, AssignmentRepository $assignmentRepository, ProgramRepository $programRepository, AssignmentSubmissionRepository $submissionRepository, AssignmentAudienceResolver $audienceResolver, AssignmentProgressSummarizer $summarizer): Response
    {
        $assignment = $this->findOrNotFound($id, $assignmentRepository, $programRepository);

        $audience = $audienceResolver->resolveAudience($assignment);
        usort($audience, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));
        $submissionsByStudentId = $submissionRepository->findAllByStudentIdForAssignment($assignment);

        $rows = array_map(static function (User $student) use ($assignment, $submissionsByStudentId): array {
            // An assignment spelling out several expected productions holds one submission per
            // production; the status reads on the first of them, which is when the student engaged.
            $submissions = $submissionsByStudentId[$student->getId()] ?? [];
            $submission = $submissions[0] ?? null;

            return [
                'student' => $student,
                'submission' => $submission,
                'submissions' => $submissions,
                'status' => match (true) {
                    null === $submission => AssignmentSubmissionStatus::Missing,
                    $assignment->isLate($submission->getSubmittedAt()) => AssignmentSubmissionStatus::Late,
                    default => AssignmentSubmissionStatus::Submitted,
                },
            ];
        }, $audience);

        return $this->render('assignment/show.html.twig', [
            'assignment' => $assignment,
            'rows' => $rows,
            'details' => $this->audienceDetails($assignment, $audienceResolver),
            'progress' => $summarizer->summarize([$assignment])[$assignment->getId()] ?? null,
        ]);
    }

    /**
     * Les classes sur lesquelles l'utilisateur peut donner du travail : celles qu'il enseigne, ou
     * toutes celles qu'il voit pour le personnel. La gestion des travaux se désactive formation par
     * formation, et une formation qui l'a fermée n'apparaît nulle part ici.
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
        return \in_array($request->query->get('embed'), [AssignmentWizardContext::MODE_MODAL, AssignmentWizardContext::MODE_PANEL], true)
            ? (string) $request->query->get('embed')
            : AssignmentWizardContext::MODE_PAGE;
    }

    /**
     * Où « Annuler » et l'enregistrement ramènent : la séance quand le travail y est rattaché, la
     * liste des travaux sinon. On revient toujours à l'écran d'où l'on parlait de ce travail.
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
        $sessionId = $request->query->getInt('seance');
        if (0 !== $sessionId) {
            $session = $lessonSessionRepository->find($sessionId);

            // Le rattachement ne se demande jamais : il vient du point d'entrée. Encore faut-il que
            // ce point d'entrée soit une séance d'une classe où l'on enseigne.
            if ($session instanceof LessonSession && $this->isAmong($session->getProgram(), $programs)) {
                return AssignmentWizardContext::forLessonSession(
                    $session,
                    LessonLogSection::tryFrom((string) $request->query->get('temps')) ?? LessonLogSection::After,
                    $this->returnUrlFor($session),
                    $mode,
                );
            }
        }

        // From an audio recording: the class, the options and the nature all follow from it, and
        // "Annuler" goes back to the recording rather than to the list.
        $recordingId = $request->query->getInt('recording');
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

        $listUrl = $this->generateUrl('app_assignments');
        $programId = $request->query->getInt('classe');
        foreach ($programs as $program) {
            if ($program->getId() === $programId) {
                return AssignmentWizardContext::forProgram($program, $listUrl, $mode);
            }
        }

        return AssignmentWizardContext::generic($listUrl, $mode);
    }

    /** Les trois cartes « Visibilité pour les étudiants » se lisent depuis la seule date stockée. */
    private function visibilityOf(Assignment $assignment): string
    {
        return match (true) {
            null === $assignment->getVisibleAt() => AssignmentWizardType::VISIBILITY_HIDDEN,
            $assignment->getVisibleAt() > new \DateTimeImmutable() => AssignmentWizardType::VISIBILITY_SCHEDULED,
            default => AssignmentWizardType::VISIBILITY_NOW,
        };
    }

    /**
     * Les supports décrochés à la modification. Cochés dans l'écran, ils ne partent qu'à
     * l'enregistrement - rien n'est écrit avant, à la modification comme à la création - et le
     * fichier stocké s'en va avec sa ligne, faute de quoi il resterait seul sur S3.
     */
    private function removeDroppedAttachments(Assignment $assignment, Request $request, FileUploadService $fileUploadService): void
    {
        $droppedIds = array_map('intval', $request->request->all('removed_attachments'));

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
     * Le travail tel qu'il se présente à l'ouverture de l'assistant : ce que le point d'entrée sait
     * déjà. Rien n'est persisté - c'est un objet de formulaire, pas un brouillon.
     *
     * @param list<Program> $programs
     */
    private function prefilledAssignment(AssignmentWizardContext $context, array $programs): Assignment
    {
        $assignment = new Assignment($context->program ?? $programs[0]);

        // Sans classe connue, l'étape 1 doit s'ouvrir vide et bloquer - la première classe de la
        // liste n'est là que pour donner un porteur à l'objet.
        if (null === $context->program) {
            $assignment->setProgram(null);
        }

        $assignment->setAudienceType($context->audienceType);

        // An assignment born of a recording is a listening and nothing else: the nature is not
        // offered (see AssignmentWizardType, which then holds only that one) and the recording's name
        // makes a far more useful title than an empty field.
        $assignment->setNature(null !== $context->audioRecording ? AssignmentNature::Listening : AssignmentNature::ToSubmit);
        $assignment->setAudioRecording($context->audioRecording);
        $assignment->setTitle($context->audioRecording?->getName());
        $assignment->setDueDate($this->defaultDueDate($context));
        $assignment->setLessonSession($context->lessonSession);
        $assignment->setLessonLogSection($context->lessonLogSection);
        $assignment->setTopic($context->topic);

        foreach ($context->options as $option) {
            $assignment->addOption($option);
        }

        return $assignment;
    }

    // « Prochaine séance » de la maquette : la semaine suivante à l'heure de la séance quand on
    // vient d'un cours, demain matin sinon.
    private function defaultDueDate(AssignmentWizardContext $context): \DateTimeImmutable
    {
        $start = $context->lessonSession?->getStartAt();

        return null !== $start
            ? $start->modify('+7 days')
            : (new \DateTimeImmutable('tomorrow'))->setTime(8, 0);
    }

    /**
     * Les deux raccourcis d'échéance de l'étape 4 - « Prochaine séance » et « Fin de semaine ». Ils
     * se calculent ici, où l'on connaît la séance d'origine ; l'écran ne fait que les proposer.
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
     * Les destinataires nommés un par un, cochés dans la liste de l'étape 1. Même convention que
     * ProgramAssignmentController : des cases brutes plutôt qu'un champ de formulaire, la liste
     * dépendant de la classe choisie dans le même écran.
     */
    private function applyAudience(Assignment $assignment, Request $request, UserRepository $userRepository): void
    {
        $program = $assignment->getProgram();

        // Une option cochée pour une autre classe n'a rien à faire ici : l'écran les masque, le
        // serveur les retire.
        foreach ($assignment->getOptions()->toArray() as $option) {
            if (AssignmentAudienceType::Option !== $assignment->getAudienceType() || !$this->optionBelongsTo($option, $program)) {
                $assignment->removeOption($option);
            }
        }

        foreach ($assignment->getManualRecipients()->toArray() as $recipient) {
            $assignment->removeManualRecipient($recipient);
        }

        if (AssignmentAudienceType::Manual === $assignment->getAudienceType() && null !== $program) {
            $submittedIds = array_map('intval', $request->request->all('manual_recipients'));
            foreach ($userRepository->findByIdsForProgram($program, $submittedIds) as $student) {
                $assignment->addManualRecipient($student);
            }
        }

        if (AssignmentAudienceType::GroupBatch !== $assignment->getAudienceType() || $assignment->getGroupBatch()?->getProgram()->getId() !== $program?->getId()) {
            $assignment->setGroupBatch(null);
        }
    }

    /**
     * Chaque type n'emporte qu'une partie des champs de l'étape 3 ; les autres restent dans le DOM
     * et doivent être remis à leur place, faute de quoi un quiz choisi puis abandonné suivrait un
     * travail devenu une lecture.
     */
    private function applyNatureFields(Assignment $assignment): void
    {
        $nature = $assignment->getNature();

        if (AssignmentNature::Quiz !== $nature) {
            $assignment->setQuizInstance(null);
            // The target only ever qualifies a quiz: it must not survive a change of nature, or a
            // reading would silently become impossible to complete.
            $assignment->setMinimumScorePercent(null);
        }

        if (AssignmentNature::Listening !== $nature) {
            $assignment->setAudioRecording(null);
        }

        if (AssignmentNature::SelfAssessment !== $nature) {
            $assignment->setEvaluation(null);
            $assignment->setSelfAssessmentFeedback(null);
        } else {
            // La maquette annonce un seul retour possible - « note comparée à la sienne » - et ne
            // pose donc pas la question.
            $assignment->setSelfAssessmentFeedback($assignment->getSelfAssessmentFeedback() ?? SelfAssessmentFeedback::Comparison);
        }

        if (!$assignment->expectsSubmission()) {
            $assignment->setLateSubmissionAllowed(false);

            foreach ($assignment->getExpectedProductions()->toArray() as $production) {
                $assignment->removeExpectedProduction($production);
            }
        }

        if (AssignmentNature::ToRead !== $nature) {
            $assignment->setReadTrackingEnabled(false);
        }

        // Une production sans nom n'annonce rien : la ligne restée vide est simplement abandonnée.
        $position = 0;
        foreach ($assignment->getExpectedProductions()->toArray() as $production) {
            if ('' === trim($production->getName())) {
                $assignment->removeExpectedProduction($production);

                continue;
            }

            $production->setPosition($position++);
        }

        if (!$assignment->isGraded()) {
            $assignment->setGradingVisibleToStudents(false);
        }
    }

    /** Les trois cartes « Visibilité pour les étudiants » se rangent dans une seule date. */
    private function applyVisibility(Assignment $assignment, \Symfony\Component\Form\FormInterface $form): void
    {
        $assignment->setVisibleAt(match ($form->get('visibility')->getData()) {
            AssignmentWizardType::VISIBILITY_NOW => new \DateTimeImmutable(),
            AssignmentWizardType::VISIBILITY_SCHEDULED => $form->get('visibleAt')->getData() ?? new \DateTimeImmutable(),
            default => null,
        });
    }

    /**
     * Les supports voyagent avec le formulaire et ne deviennent des lignes qu'ici : le travail
     * n'existait pas avant, il n'y avait donc rien à quoi les rattacher.
     */
    private function applyAttachments(Assignment $assignment, \Symfony\Component\Form\FormInterface $form, FileUploadService $fileUploadService): void
    {
        /** @var list<UploadedFile> $files */
        $files = $form->get('attachmentFiles')->getData() ?? [];

        foreach ($files as $file) {
            $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
            $key = $fileUploadService->upload(
                self::ATTACHMENT_UPLOAD_PREFIX,
                sprintf('%d-%s.%s', time(), bin2hex(random_bytes(4)), $extension),
                $file,
            );

            (new AssignmentAttachment($assignment, $file->getClientOriginalName(), AssignmentAttachmentSourceType::Upload))->setStorageKey($key);
        }

        foreach (preg_split('/\R/', (string) $form->get('attachmentLinks')->getData()) ?: [] as $line) {
            $url = trim($line);

            if ('' === $url) {
                continue;
            }

            (new AssignmentAttachment($assignment, $url, AssignmentAttachmentSourceType::Link))->setUrl($url);
        }
    }

    /**
     * Le rattachement automatique, jamais demandé : la séance et son temps quand on vient du cahier
     * de texte, et la matière - celle de la séance, ou l'unique matière que l'enseignant assure
     * dans la classe choisie. Plusieurs matières possibles et aucune séance : on n'invente rien, le
     * travail se lira sans mention de matière.
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
     * Les deux seules règles que le type impose : un quiz à dérouler pour un travail de type Quiz,
     * une évaluation à estimer pour une autoévaluation. Sans elles l'étudiant n'aurait rien à
     * ouvrir. Elles se vérifient ici et non dans le type de formulaire, qui ne voit pas les autres
     * champs au moment de se construire.
     */
    private function validateNatureRequirements(\Symfony\Component\Form\FormInterface $form): void
    {
        /** @var Assignment $assignment */
        $assignment = $form->getData();

        if (AssignmentNature::Quiz === $assignment->getNature() && null === $assignment->getQuizInstance()) {
            $form->get('quizInstance')->addError(new \Symfony\Component\Form\FormError($this->translator->trans('assignmentWizardQuizRequiredMessage')));
        }

        if (AssignmentNature::SelfAssessment === $assignment->getNature() && null === $assignment->getEvaluation()) {
            $form->get('evaluation')->addError(new \Symfony\Component\Form\FormError($this->translator->trans('assignmentWizardEvaluationRequiredMessage')));
        }

        // Une classe est obligatoire, et l'écran bloque déjà l'étape 1 sans elle - la vérification
        // ici est le filet, pour une requête qui n'aurait pas suivi l'écran.
        if (null === $assignment->getProgram()) {
            $form->get('program')->addError(new \Symfony\Component\Form\FormError($this->translator->trans('assignmentWizardClassRequiredMessage')));
        }
    }

    /**
     * La sous-ligne d'un travail en liste : ce qui le distingue d'un autre du même type - qui il
     * vise, et ce qu'il a de particulier.
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

    // L'état d'un travail tel que la colonne Échéance le donne à lire : masqué, échu, imminent ou
    // à venir - dans cet ordre, un travail masqué n'ayant pas d'échéance qui compte encore.
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
     * Les élèves de chaque classe candidate, pour la liste à cocher de l'étape 1 - toutes les
     * classes d'un coup, l'écran n'affichant que celle qui est choisie.
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
     * La composition des lots de groupes de l'enseignant, prête à afficher en chips récapitulatives
     * (étape 1, ciblage « Par groupes »). Les identifiants figés du lot sont recroisés avec la
     * classe d'aujourd'hui : un étudiant parti depuis n'apparaît plus dans son groupe.
     *
     * @param list<Program> $programs
     *
     * @return array<int, list<list<string>>> identifiant du lot => groupes => noms des membres
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
