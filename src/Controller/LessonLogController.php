<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Assignment;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonLogAttachmentView;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\ProgressionSeance;
use App\Entity\User;
use App\Enum\AssignmentNature;
use App\Enum\Feature;
use App\Enum\LessonLogAttachmentSourceType;
use App\Enum\LessonLogSection;
use App\Form\LessonLogAttachmentType;
use App\Form\LessonLogType;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentViewRepository;
use App\Repository\LessonLogAttachmentRepository;
use App\Repository\LessonLogAttachmentViewRepository;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\ProgressionSeancePlacementRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\LessonLogVoter;
use App\Service\AssignmentAudienceResolver;
use App\Service\Console\ConsoleLessonLogDraft;
use App\Service\FileUploadService;
use App\Service\LessonLogBoard;
use App\Service\LessonLogImporter;
use App\Service\QueryValue;
use App\Service\SeanceContentResolver;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

// The "cahier de texte" for a single LessonSession - see design/validated/lesson-log-cahier-de-texte.md.
// Reachable from the timetable (both the read-only student/teacher page and the staff settings
// tab) via LessonSessionEventFormatter's logUrl. Unlike ProgramTimetableSettingsController, this
// isn't staff-only: viewing follows program visibility, editing is staff-or-the-session's-own-
// teacher (see LessonLogVoter), so access is checked per-route rather than class-wide.
#[RequiresFeature(Feature::LessonLog)]
class LessonLogController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    private const string ATTACHMENT_UPLOAD_PREFIX = 'lesson-logs/';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    /**
     * Course view (design_handoff_cahier_de_texte 1b): where a program's cahier de texte stands,
     * séance by séance. A screen for navigating and spotting the gaps, not for editing - entry
     * happens on the séance page, which every row links to.
     */
    #[Route(path: '/programs/{id}/lesson-log', name: 'app_program_lesson_logs')]
    public function courseView(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, AssignmentRepository $assignmentRepository, AssignmentViewRepository $viewRepository, AssignmentCompletionRepository $completionRepository, LessonLogAttachmentViewRepository $attachmentViewRepository, ProgramStudentOptionRepository $studentOptionRepository, AssignmentAudienceResolver $audienceResolver, LessonLogBoard $board): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        // A teacher screen, and not a « list » version of the cahier de texte: it shows every
        // séance regardless of the visibility set part by part, and the class's read tracking with
        // it. So it closes itself to students rather than filtering itself.
        if (!$this->accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        $sessions = $lessonSessionRepository->findForProgram($program);
        usort($sessions, static fn (LessonSession $a, LessonSession $b): int => [$a->getDay(), $a->getStartHour()] <=> [$b->getDay(), $b->getStartHour()]);

        // A lesson log belongs to whoever taught the session: showing a teacher the whole class's
        // timetable would bury their own sessions under their colleagues'. Own sessions by default,
        // the switch opens it back up to the class as a whole - for the head teacher checking that
        // the log is being kept, and for whoever covers an absent colleague.
        $viewer = $this->getUser();
        $mine = array_values(array_filter(
            $sessions,
            static fn (LessonSession $session): bool => $session->getTeacher() === $viewer,
        ));

        // Whoever teaches nothing here - a head of studies, an administrator - would otherwise land
        // on an empty screen with every arrow dead, and no way to guess the switch is what fixes
        // it. They get the whole class instead, which is the only thing the log can mean for them.
        $mineOnly = [] !== $mine && !$request->query->getBoolean('all');
        $sessions = $mineOnly ? $mine : $sessions;

        $logsBySessionId = [];
        foreach ($lessonLogRepository->findForProgram($program) as $log) {
            $logsBySessionId[$log->getLessonSession()?->getId()] = $log;
        }

        // A whole year of sessions doesn't fit in one column, so the list is cut into weeks and
        // only the selected one is rendered.
        $rowsByWeek = [];
        foreach ($sessions as $session) {
            $day = $session->getDay();
            if (null === $day) {
                continue;
            }

            $log = $logsBySessionId[$session->getId()] ?? null;
            $rowsByWeek[$board->weekStartOf($day)->format('Y-m-d')][] = [
                'session' => $session,
                'log' => $log,
                'state' => $board->stateOf($log?->getContent(LessonLogSection::During)),
            ];
        }
        ksort($rowsByWeek);

        $week = $board->weekToDisplay(QueryValue::string($request, 'week'), array_keys($rowsByWeek), new \DateTimeImmutable('today'));
        $rows = $rowsByWeek[$week->format('Y-m-d')] ?? [];
        $filled = \count(array_filter($rows, static fn (array $row): bool => 'filled' === $row['state']));

        // The séance put in preview: the one asked for, else the first unfilled one, else the last
        // - what a teacher comes looking for when opening this screen.
        // Scoped to the displayed week, otherwise the preview would describe a session that isn't
        // in the list.
        $selectedId = QueryValue::int($request, 'seance');
        $selected = null;
        foreach ($rows as $row) {
            if ($row['session']->getId() === $selectedId) {
                $selected = $row;
            }
        }
        foreach ($rows as $row) {
            $selected ??= 'empty' === $row['state'] ? $row : null;
        }
        // The week can be empty (holidays, internship), in which case there is nothing to preview.
        $selected ??= [] === $rows ? null : $rows[array_key_last($rows)];

        // Read tracking for the previewed séance: this is where one comes to see where the class
        // stands, so it may as well be said here rather than forcing the séance open to find out.
        $selectedSession = $selected['session'] ?? null;
        $selectedWorks = null !== $selectedSession ? $this->worksBySection($assignmentRepository, $selectedSession) : [];

        // The arrows jump to weeks that actually have class with this program - a holiday or
        // internship week is not something to click through one week at a time. They therefore
        // stop at the program's own bounds on their own, since no session exists beyond them.
        $weeks = array_keys($rowsByWeek);
        $current = $week->format('Y-m-d');
        // ?->getDay() would not save us here: reading a missing array key raises first.
        $lowerBounds = array_filter([$program->getEffectiveStartDate(), ($sessions[0] ?? null)?->getDay()]);
        $upperBounds = array_filter([$program->getEffectiveEndDate(), ($sessions[array_key_last($sessions)] ?? null)?->getDay()]);
        $previousWeeks = array_filter($weeks, static fn (string $candidate): bool => $candidate < $current);
        $nextWeeks = array_filter($weeks, static fn (string $candidate): bool => $candidate > $current);

        return $this->render('program/lesson_logs.html.twig', [
            'program' => $program,
            'rows' => $rows,
            'filled' => $filled,
            'mineOnly' => $mineOnly,
            'week' => $week,
            'weekEnd' => $week->modify('+6 days'),
            'previousWeek' => [] === $previousWeeks ? null : end($previousWeeks),
            'nextWeek' => [] === $nextWeeks ? null : reset($nextWeeks),
            // Calendar bounds: the program's dates WIDENED to whatever the timetable actually
            // holds. The dates alone would be wrong in both directions - too narrow to reach a
            // session scheduled outside them (which happens), and too wide to be worth clamping to
            // if the timetable stops early. Empty weeks in between stay reachable on purpose:
            // seeing that a week is empty is an answer too.
            'weekPickerMin' => [] === $lowerBounds ? null : min($lowerBounds),
            'weekPickerMax' => [] === $upperBounds ? null : max($upperBounds),
            'selected' => $selected,
            'sections' => LessonLogSection::cases(),
            'worksBySection' => $selectedWorks,
            'workTracking' => null !== $selectedSession ? $this->workTracking($selectedWorks, $viewRepository, $completionRepository, $audienceResolver) : [],
            'documentTracking' => null !== ($selected['log'] ?? null) ? $this->attachmentTracking($selected['log'], $selectedSession, $attachmentViewRepository, $studentOptionRepository) : null,
        ]);
    }

    /**
     * The Monday of a date's week, at midnight - the key sessions are grouped under, and the form
     * the week travels in through the URL.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log', name: 'app_program_timetable_session_log', methods: ['GET', 'POST'])]
    public function show(int $id, int $sessionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, AssignmentRepository $assignmentRepository, ProgressionSeancePlacementRepository $placementRepository, AssignmentCompletionRepository $completionRepository, AssignmentViewRepository $viewRepository, LessonLogAttachmentViewRepository $attachmentViewRepository, ProgramStudentOptionRepository $studentOptionRepository, AssignmentAudienceResolver $audienceResolver, LessonLogImporter $importer, SeanceContentResolver $seanceContentResolver, ConsoleLessonLogDraft $consoleImporter): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::VIEW, $session);

        $canEdit = $this->isGranted(LessonLogVoter::EDIT, $session);
        $log = $lessonLogRepository->findOneBySession($session);
        $isNew = null === $log;

        if ($isNew) {
            $log = new LessonLog($session);
            // « Coller dans le cahier de texte », depuis une console de machine : ce que la classe
            // a fait est proposé ici plutôt que recopié à la main. Uniquement sur un cahier encore
            // vide, et uniquement en proposition - rien n'est enregistré tant que la personne n'a
            // pas validé le formulaire, exactement comme une frappe au clavier.
            $log->setContenuRealise($consoleImporter->contentFor(
                QueryValue::nullableInt($request, 'console'),
                $this->currentUser(),
            ));
        }

        // Disabling the parent form cascades to every child field (core Symfony Form behavior),
        // so a viewer without edit rights still sees the same template with read-only widgets
        // instead of needing a second, parallel read-view template.
        $form = $this->createForm(LessonLogType::class, $log, ['disabled' => !$canEdit]);

        if ($canEdit) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->stampAuditFields($log, !$isNew);
                $entityManager->persist($log);
                $entityManager->flush();

                $this->addFlash('success', 'lessonLogUpdatedFlashMessage');

                return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
            }
        }

        $works = $this->worksBySection($assignmentRepository, $session);
        $sectionViews = $this->sectionViews($program, $log, $works);
        $seanceInstance = $canEdit ? $seanceContentResolver->forLessonSession($session) : null;

        // Giving or editing an assignment happens in the wizard (design_handoff_creation_travail
        // 2a), mounted as a modal over this page from _lesson_log_works.html.twig: the séance no
        // longer has its own assignment form, only the frame the wizard settles into.

        return $this->render('program/lesson_log.html.twig', [
            'program' => $program,
            'session' => $session,
            'log' => $log,
            'form' => $form,
            'canEdit' => $canEdit,
            // The teacher tools this page offers without their requiring the right to edit THIS
            // séance: the course view and the self-assessment tracking, open to the whole program
            // team, closed to students.
            'canViewTeacherTools' => $this->accessChecker->isProgramTeacher($program),
            'attachmentForm' => $canEdit ? $this->createForm(LessonLogAttachmentType::class) : null,
            'sections' => LessonLogSection::cases(),
            'sectionViews' => $sectionViews,
            'anySectionShown' => [] !== array_filter($sectionViews, static fn (array $view): bool => $view['shown']),
            'sequenceStrip' => $this->sequenceStrip($placementRepository, $session),
            'importSuggestions' => $canEdit ? $importer->suggestionsFor($session) : [],
            // The library séance this slot came from, if it did - first entry of the import menu.
            // Already resolved further down for pre-filling, reused as is.
            'importBrowsable' => $canEdit ? $importer->browsableFor($session) : [],
            'documentSection' => LessonLogSection::tryFrom((string) $request->query->get('document')),
            'workTracking' => $canEdit ? $this->workTracking($works, $viewRepository, $completionRepository, $audienceResolver) : [],
            'documentTracking' => $canEdit ? $this->attachmentTracking($log, $session, $attachmentViewRepository, $studentOptionRepository) : null,
            // The assignments students have already started: deletion warns about them differently.
            'worksWithProduction' => $canEdit ? $importer->worksWithProduction($session) : [],
            // Used to ask for confirmation only when the import really overwrites something.
            'importHasContent' => $canEdit && $importer->hasContent($session),
            // Only offered when it exists - see design/validated/teaching-sequence-library.md's
            // "relationship to part A". Part A fully works without part C ever being built.
            'seanceInstance' => $seanceInstance,
            // What that séance already says, part by part - offered next to each editor as a one
            // click « reprendre », where the import menu only ever offered the whole séance at
            // once. Absent keys are parts the séance says nothing about.
            'seanceDefaults' => $seanceContentResolver->defaultsFor($seanceInstance),
        ]);
    }

    /**
     * What each part lets whoever is looking see. The visibility set by the teacher (mockup 2a) so
     * far only served to display itself: it is applied here, and here only, so that the three rules
     * stay legible in the same place.
     *
     * - a part's content follows that part's visibility;
     * - a document follows its own date when it has one, otherwise its part (see
     *   LessonLogAttachment::isVisibleFor());
     * - an assignment follows its own publication, as everywhere else in the application: it also
     *   lives in « Travail à réaliser », and hiding it here would not hide it there.
     *
     * The program's teachers and the staff see everything, including what is not published - this
     * is their working screen. The right to edit THIS séance does not come into it: a colleague
     * reading someone else's séance is not a student.
     *
     * @param array<string, list<Assignment>> $works
     *
     * @return array<string, array{contentVisible: bool, attachments: list<LessonLogAttachment>, works: list<Assignment>, shown: bool}>
     */
    private function sectionViews(Program $program, LessonLog $log, array $works): array
    {
        $restricted = !$this->accessChecker->isProgramTeacher($program);
        $now = new \DateTimeImmutable();

        $views = [];
        foreach (LessonLogSection::cases() as $section) {
            $contentVisible = !$restricted || $log->isSectionVisible($section, $now);

            $attachments = array_values(array_filter(
                $log->getAttachmentsForSection($section)->toArray(),
                static fn (LessonLogAttachment $attachment): bool => !$restricted || $attachment->isVisibleFor($now),
            ));

            $sectionWorks = array_values(array_filter(
                $works[$section->value] ?? [],
                static fn (Assignment $work): bool => !$restricted || $work->isVisibleFor($now),
            ));

            $views[$section->value] = [
                'contentVisible' => $contentVisible,
                'attachments' => $attachments,
                'works' => $sectionWorks,
                // The card disappears when nothing is left to read in it: a hidden part must not
                // leave an empty frame that still says « something is happening here ».
                'shown' => !$restricted || $contentVisible || [] !== $attachments || [] !== $sectionWorks,
            ];
        }

        return $views;
    }

    /**
     * The séquence banner at the top of the cahier de texte (mockup 2a): where this séance stands
     * in the séquence carrying it, and the state of the others.
     *
     * Null when the séance hangs off no progression - the banner then disappears, which the mockup
     * explicitly provides for. The séquence's séances are ordered by their créneau rather than by
     * their declared position: that is the order the class will live them in, and the only one that
     * allows saying « done » or « upcoming ».
     *
     * @return array{title: string, seances: list<array{label: string, state: string}>, index: int, total: int}|null
     */
    private function sequenceStrip(ProgressionSeancePlacementRepository $placementRepository, LessonSession $session): ?array
    {
        $sequence = $placementRepository->findOneByLessonSession($session)?->getProgressionSeance()?->getProgressionSequence();
        if (null === $sequence) {
            return null;
        }

        $rows = [];
        foreach ($sequence->getActiveSeances() as $seance) {
            $day = null;
            foreach ($seance->getActivePlacements() as $placement) {
                $placedDay = $placement->getLessonSession()?->getDay();
                $day = null === $day ? $placedDay : min($day, $placedDay);
            }

            $rows[] = ['seance' => $seance, 'day' => $day];
        }

        usort($rows, static fn (array $a, array $b): int => [null === $a['day'], $a['day']] <=> [null === $b['day'], $b['day']]);

        $today = new \DateTimeImmutable('today');
        $seances = [];
        $index = 0;
        foreach ($rows as $position => $row) {
            $isCurrent = [] !== $row['seance']->getActivePlacements() && $this->placementsCover($row['seance'], $session);
            if ($isCurrent) {
                $index = $position + 1;
            }

            $seances[] = [
                'label' => sprintf('S%d · %s', $position + 1, $row['seance']->getTitle()),
                'state' => match (true) {
                    $isCurrent => 'current',
                    null !== $row['day'] && $row['day'] < $today => 'done',
                    default => 'upcoming',
                },
            ];
        }

        return ['title' => $sequence->getTitle(), 'seances' => $seances, 'index' => $index, 'total' => \count($seances)];
    }

    private function placementsCover(ProgressionSeance $seance, LessonSession $session): bool
    {
        foreach ($seance->getActivePlacements() as $placement) {
            if ($placement->getLessonSession()?->getId() === $session->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opening a document: the trace is recorded here, then the student is sent on to the file or
     * the link. Going through the application rather than pointing at the file directly is the only
     * way to know that a support was opened, and not merely listed.
     *
     * Only student openings are counted: a teacher re-reading their own cahier de texte has no
     * business inflating its statistics.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/documents/{attachmentId}/open', name: 'app_program_timetable_session_log_attachment_open', methods: ['GET'], requirements: ['attachmentId' => '\\d+'])]
    public function openAttachment(int $id, int $sessionId, int $attachmentId, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogAttachmentRepository $attachmentRepository, LessonLogAttachmentViewRepository $viewRepository, FileUploadService $fileUploadService): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::VIEW, $session);

        $attachment = $attachmentRepository->find($attachmentId) ?? throw $this->createNotFoundException();
        if ($attachment->getLessonLog()?->getLessonSession()?->getId() !== $session->getId()) {
            throw $this->createNotFoundException();
        }

        // The link is not shown until the document is published, but an address can be guessed:
        // without this check, visibility would rest on the template alone.
        if (!$this->accessChecker->isProgramTeacher($program) && !$attachment->isVisibleFor()) {
            throw $this->createNotFoundException();
        }

        if ($this->isGranted('ROLE_STUDENT')) {
            $view = $viewRepository->findOneFor($attachment, $this->currentUser());
            $view ? $view->registerOpening() : $entityManager->persist(new LessonLogAttachmentView($attachment, $this->currentUser()));
            $entityManager->flush();
        }

        $target = LessonLogAttachmentSourceType::Upload === $attachment->getType() && null !== $attachment->getStorageKey()
            ? $fileUploadService->url($attachment->getStorageKey())
            : (string) $attachment->getUrl();

        return $this->redirect($target);
    }

    /**
     * Open tracking for a séance's documents: per document, and for the whole set.
     *
     * The whole set only counts students who opened ALL the documents - having opened three out of
     * four is not having read everything, and an average would hide it.
     *
     * The audience is the séance's own: its options when it carries any - a half-group practical
     * does not concern the other half -, otherwise the whole program.
     *
     * @return array{perAttachment: array<int, int>, all: int, total: int}
     */
    private function attachmentTracking(LessonLog $log, LessonSession $session, LessonLogAttachmentViewRepository $viewRepository, ProgramStudentOptionRepository $studentOptionRepository): array
    {
        $attachments = $log->getAttachments()->toArray();

        $audience = $session->getOptions()->isEmpty()
            ? $session->getProgram()?->getStudents()->toArray() ?? []
            : $studentOptionRepository->findStudentsForProgramAndOptions($session->getProgram(), $session->getOptions());

        return [
            'perAttachment' => $viewRepository->countByAttachment($attachments),
            'all' => $viewRepository->countStudentsHavingOpenedAll($attachments),
            'total' => \count($audience),
        ];
    }

    /**
     * The tracking shown under each assignment (mockup 2a, « lu par 19 / 24 »): where the targeted
     * audience stands.
     *
     * Two counters, two kinds of evidence:
     *  - « ouvert par », for assignments to read: the assignment page was opened, an observed and
     *    dated fact the student does not choose to produce. This is read tracking proper, and it
     *    does not claim the text was read - only that it was displayed;
     *  - « fait par », for any assignment settled by a declaration: the student says they have
     *    finished. Less reliable, but it is the only thing an exercise on paper can produce.
     *
     * @param array<string, list<Assignment>> $worksBySection
     *
     * @return array<int, array{opened: int|null, done: int|null, total: int}>
     */
    private function workTracking(array $worksBySection, AssignmentViewRepository $viewRepository, AssignmentCompletionRepository $completionRepository, AssignmentAudienceResolver $audienceResolver): array
    {
        $works = [];
        foreach ($worksBySection as $sectionWorks) {
            foreach ($sectionWorks as $work) {
                $works[] = $work;
            }
        }

        $opened = $viewRepository->countByAssignment($works);
        $done = $completionRepository->countByAssignment($works);

        $tracking = [];
        foreach ($works as $work) {
            $id = (int) $work->getId();
            $tracking[$id] = [
                'opened' => AssignmentNature::ToRead === $work->getNature() ? ($opened[$id] ?? 0) : null,
                'done' => $work->getNature()->expectsSelfDeclaration() ? ($done[$id] ?? 0) : null,
                'total' => \count($audienceResolver->resolveAudience($work)),
            ];
        }

        return $tracking;
    }

    /**
     * The séance's assignments, sorted by part. The « during » part takes none, but the key exists
     * so the template can loop over the three parts without asking itself the question.
     *
     * @return array<string, list<Assignment>>
     */
    private function worksBySection(AssignmentRepository $assignmentRepository, LessonSession $session): array
    {
        $works = array_fill_keys(array_map(static fn (LessonLogSection $s): string => $s->value, LessonLogSection::cases()), []);

        foreach ($assignmentRepository->findForLessonSession($session) as $assignment) {
            $section = $assignment->getLessonLogSection() ?? LessonLogSection::After;
            $works[$section->value][] = $assignment;
        }

        return $works;
    }

    /**
     * Delete a given assignment. A deliberate gesture, including when students have already
     * submitted or declared themselves finished - the import, by contrast, refuses and spares them.
     * The screen warns more firmly in that case, since deletion carries their productions away too.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/assignments/{assignmentId}/delete', name: 'app_program_timetable_session_log_work_remove', methods: ['POST'], requirements: ['assignmentId' => '\d+'])]
    public function removeWork(int $id, int $sessionId, int $assignmentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, AssignmentRepository $assignmentRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        if (!$this->isCsrfTokenValid('lesson_log_work_remove', $request->request->get('delete_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $assignment = $assignmentRepository->find($assignmentId) ?? throw $this->createNotFoundException();
        if ($assignment->getLessonSession()?->getId() !== $session->getId()) {
            throw $this->createNotFoundException();
        }

        $entityManager->remove($assignment);
        $entityManager->flush();

        $this->addFlash('success', 'lessonLogWorkRemovedFlashMessage');

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    /**
     * Take back the library séance this slot came from: the first entry of the import menu, and the
     * only one that replaces instead of completing - the source séance is authoritative.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/import-library', name: 'app_program_timetable_session_log_import_library', methods: ['POST'])]
    public function importFromLibrary(int $id, int $sessionId, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, SeanceContentResolver $seanceContentResolver, LessonLogImporter $importer, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        if (!$this->isCsrfTokenValid('lesson_log_import', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $seance = $seanceContentResolver->forLessonSession($session) ?? throw $this->createNotFoundException();
        $kept = $importer->importFromLibrary($seance, $session, $this->currentUser());

        $this->addFlash('success', 'lessonLogImportedFlashMessage');

        // Says what survived the import rather than leaving the teacher to find out: those
        // assignments already carry productions, and they alone can decide to delete them.
        if (0 < $kept) {
            $this->addFlash('info', $translator->trans('lessonLogImportKeptWorksMessage', ['%count%' => $kept]));
        }

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    /**
     * Take back another séance's cahier de texte (mockup 2a). The source séance must be comparable
     * - the same matière, in another program, or on this class's TWIN créneau
     * (design/validated/co-animation.md) - and the teacher must be able to edit the target; they
     * need not, on the other hand, be able to edit the source, which they only read.
     *
     * Nothing about the authorisation moved when the twin was added, and that is the point: the
     * route already re-checked the posted sourceId against LessonLogImporter::browsableFor() rather
     * than trusting it, so widening the finder widened the route consistently. That guard is why
     * this is a one-line feature.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/import/{sourceId}', name: 'app_program_timetable_session_log_import', methods: ['POST'], requirements: ['sourceId' => '\d+'])]
    public function importFromSession(int $id, int $sessionId, int $sourceId, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogImporter $importer, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        if (!$this->isCsrfTokenValid('lesson_log_import', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $source = $lessonSessionRepository->find($sourceId) ?? throw $this->createNotFoundException();

        // The source must appear among the séances offered: that is what guarantees it carries the
        // same matière, and that we are not copying just any cahier de texte from the database.
        $allowed = false;
        foreach ($importer->browsableFor($session) as $candidate) {
            $allowed = $allowed || $candidate->getId() === $source->getId();
        }

        if (!$allowed) {
            throw $this->createNotFoundException();
        }

        $importer->import($source, $session, $this->currentUser());

        // Between two teachers, only the three texts travelled. Saying so here rather than nowhere:
        // a teacher who notices the missing documents an hour later reads it as a bug, and an
        // administrative record that quietly arrives incomplete is worse than one that says what it
        // is missing.
        $sourceTeacher = $source->getTeacher();
        if (null !== $sourceTeacher && $sourceTeacher !== $session->getTeacher()) {
            $this->addFlash('success', $translator->trans('lessonLogImportTwinFlashMessage', [
                '%name%' => $sourceTeacher->getDisplayName() ?? $sourceTeacher->getUsername(),
            ]));
        } else {
            $this->addFlash('success', 'lessonLogImportedFlashMessage');
        }

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/attachments', name: 'app_program_timetable_session_log_attachments_new', methods: ['POST'])]
    public function addAttachment(int $id, int $sessionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, UploadIntake $uploadIntake): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        $log = $lessonLogRepository->findOneBySession($session);
        $isNew = null === $log;

        if ($isNew) {
            $log = new LessonLog($session);
        }

        $form = $this->createForm(LessonLogAttachmentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var StagedUpload|null $file */
            $file = $form->get('file')->getData();
            $url = $form->get('url')->getData();
            $label = $form->get('label')->getData();

            if ((null === $file) === (null === $url)) {
                // Either both empty or both filled - exactly one source is expected.
                $this->addFlash('error', null === $file ? 'lessonLogAttachmentMissingSourceFlashMessage' : 'lessonLogAttachmentBothSourcesFlashMessage');
            } else {
                if ($isNew) {
                    $this->stampAuditFields($log, false);
                }

                $attachment = new LessonLogAttachment($log, $label);
                // The part to attach the document to comes from the « + Ajouter » link clicked;
                // failing that, the work done, the only place documents used to be displayed.
                $attachment->setSection(LessonLogSection::tryFrom((string) $request->request->get('section')) ?? LessonLogSection::During);

                if (null !== $file) {
                    $extension = UploadIntake::extension($file);
                    $key = $uploadIntake->store(
                        $file,
                        self::ATTACHMENT_UPLOAD_PREFIX,
                        sprintf('%d-%d-%s.%s', $session->getId(), time(), bin2hex(random_bytes(4)), $extension),
                    );
                    $libraryNode = UploadIntake::libraryNodeOf($file);
                    $attachment->setType(null === $libraryNode ? LessonLogAttachmentSourceType::Upload : LessonLogAttachmentSourceType::Library);
                    $attachment->setStorageKey($key);
                    $attachment->setLibraryNode($libraryNode);
                } else {
                    $attachment->setType(LessonLogAttachmentSourceType::Link);
                    $attachment->setUrl($url);
                }

                $entityManager->persist($log);
                $entityManager->persist($attachment);
                $entityManager->flush();

                $this->addFlash('success', 'lessonLogAttachmentAddedFlashMessage');
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/attachments/{attachmentId}/delete', name: 'app_program_timetable_session_log_attachments_delete', methods: ['POST'])]
    public function deleteAttachment(int $id, int $sessionId, int $attachmentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogAttachmentRepository $attachmentRepository, FileUploadService $fileUploadService): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        $attachment = $attachmentRepository->find($attachmentId) ?? throw $this->createNotFoundException();

        if ($attachment->getLessonLog()->getLessonSession()?->getId() !== $session->getId()) {
            throw $this->createNotFoundException();
        }

        // A separately named field: the button is a formaction of the three-part form, which
        // already has its own _token.
        if (!$this->isCsrfTokenValid('lesson_log_attachment_delete', $request->request->get('attachment_delete_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (LessonLogAttachmentSourceType::Upload === $attachment->getType() && null !== $attachment->getStorageKey()) {
            $fileUploadService->delete($attachment->getStorageKey());
        }

        $entityManager->remove($attachment);
        $entityManager->flush();

        $this->addFlash('success', 'lessonLogAttachmentRemovedFlashMessage');

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $program;
    }

    private function findLessonSessionOrNotFound(LessonSessionRepository $repository, Program $program, int $sessionId): LessonSession
    {
        $lessonSession = $repository->find($sessionId) ?? throw $this->createNotFoundException();

        if ($lessonSession->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $lessonSession;
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
