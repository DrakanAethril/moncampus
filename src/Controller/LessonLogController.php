<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\QueryValue;
use App\Entity\Assignment;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonLogAttachmentView;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\ProgressionSeance;
use App\Entity\User;
use App\Enum\AssignmentNature;
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
use App\Service\FileUploadService;
use App\Service\LessonLogBoard;
use App\Service\LessonLogImporter;
use App\Service\SeanceContentResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

// The "cahier de texte" for a single LessonSession - see design/validated/lesson-log-cahier-de-texte.md.
// Reachable from the timetable (both the read-only student/teacher page and the staff settings
// tab) via LessonSessionEventFormatter's logUrl. Unlike ProgramTimetableSettingsController, this
// isn't staff-only: viewing follows program visibility, editing is staff-or-the-session's-own-
// teacher (see LessonLogVoter), so access is checked per-route rather than class-wide.
class LessonLogController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    private const string ATTACHMENT_UPLOAD_PREFIX = 'lesson-logs/';

    public function __construct(private readonly StructureAccessChecker $accessChecker)
    {
    }

    /**
     * Vue cours (design_handoff_cahier_de_texte 1b) : où en est le cahier de texte d'une formation,
     * séance par séance. Écran de navigation et de repérage des trous, pas d'édition - la saisie se
     * fait sur la page de séance, vers laquelle chaque ligne renvoie.
     */
    #[Route(path: '/programs/{id}/lesson-log', name: 'app_program_lesson_logs')]
    public function courseView(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, AssignmentRepository $assignmentRepository, AssignmentViewRepository $viewRepository, AssignmentCompletionRepository $completionRepository, LessonLogAttachmentViewRepository $attachmentViewRepository, ProgramStudentOptionRepository $studentOptionRepository, AssignmentAudienceResolver $audienceResolver, LessonLogBoard $board): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        // Écran d'enseignant, et non une version « liste » du cahier de texte : il montre chaque
        // séance sans égard pour la visibilité réglée temps par temps, et le suivi de lecture de la
        // classe avec. Il se ferme donc aux étudiants plutôt que de se filtrer.
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

        $week = $board->weekToDisplay($request->query->getString('week'), array_keys($rowsByWeek), new \DateTimeImmutable('today'));
        $rows = $rowsByWeek[$week->format('Y-m-d')] ?? [];
        $filled = \count(array_filter($rows, static fn (array $row): bool => 'filled' === $row['state']));

        // La séance mise en aperçu : celle demandée, sinon la première non remplie, sinon la
        // dernière - ce qu'un enseignant vient chercher en ouvrant cet écran.
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

        // Le suivi de lecture de la séance en aperçu : c'est ici qu'on vient voir où en est la
        // classe, autant l'y dire plutôt que d'obliger à ouvrir la séance pour le savoir.
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
    public function show(int $id, int $sessionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, AssignmentRepository $assignmentRepository, ProgressionSeancePlacementRepository $placementRepository, AssignmentCompletionRepository $completionRepository, AssignmentViewRepository $viewRepository, LessonLogAttachmentViewRepository $attachmentViewRepository, ProgramStudentOptionRepository $studentOptionRepository, AssignmentAudienceResolver $audienceResolver, LessonLogImporter $importer, SeanceContentResolver $seanceContentResolver): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::VIEW, $session);

        $canEdit = $this->isGranted(LessonLogVoter::EDIT, $session);
        $log = $lessonLogRepository->findOneBySession($session);
        $isNew = null === $log;

        if ($isNew) {
            $log = new LessonLog($session);
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

        // Donner ou modifier un travail se fait dans l'assistant (design_handoff_creation_travail
        // 2a), monté en modale par-dessus cette page depuis _lesson_log_works.html.twig : la séance
        // n'a plus son propre formulaire de travail, seulement le cadre où l'assistant se pose.

        return $this->render('program/lesson_log.html.twig', [
            'program' => $program,
            'session' => $session,
            'log' => $log,
            'form' => $form,
            'canEdit' => $canEdit,
            // Les outils d'enseignant que la page propose sans qu'ils demandent le droit de
            // modifier CETTE séance : la vue cours et le suivi des autoévaluations, ouverts à
            // toute l'équipe de la formation, fermés aux étudiants.
            'canViewTeacherTools' => $this->accessChecker->isProgramTeacher($program),
            'attachmentForm' => $canEdit ? $this->createForm(LessonLogAttachmentType::class) : null,
            'sections' => LessonLogSection::cases(),
            'sectionViews' => $sectionViews,
            'anySectionShown' => [] !== array_filter($sectionViews, static fn (array $view): bool => $view['shown']),
            'sequenceStrip' => $this->sequenceStrip($placementRepository, $session),
            'importSuggestions' => $canEdit ? $importer->suggestionsFor($session) : [],
            // La séance de bibliothèque dont ce créneau est issu, s'il l'est - première entrée du
            // menu d'import. Déjà résolue plus bas pour le pré-remplissage, réutilisée telle quelle.
            'importBrowsable' => $canEdit ? $importer->browsableFor($session) : [],
            'documentSection' => LessonLogSection::tryFrom((string) $request->query->get('document')),
            'workTracking' => $canEdit ? $this->workTracking($works, $viewRepository, $completionRepository, $audienceResolver) : [],
            'documentTracking' => $canEdit ? $this->attachmentTracking($log, $session, $attachmentViewRepository, $studentOptionRepository) : null,
            // Les travaux déjà commencés par des étudiants : la suppression les prévient autrement.
            'worksWithProduction' => $canEdit ? $importer->worksWithProduction($session) : [],
            // Sert à ne demander confirmation que lorsque l'import écrase réellement quelque chose.
            'importHasContent' => $canEdit && $importer->hasContent($session),
            // Only offered when it exists - see design/validated/teaching-sequence-library.md's
            // "relationship to part A". Part A fully works without part C ever being built.
            'seanceInstance' => $canEdit ? $seanceContentResolver->forLessonSession($session) : null,
        ]);
    }

    /**
     * Ce que chaque temps laisse voir à qui regarde. La visibilité réglée par l'enseignant
     * (maquette 2a) ne servait jusqu'ici qu'à s'afficher : elle s'applique ici, et rien qu'ici,
     * pour que les trois règles restent lisibles au même endroit.
     *
     * - le contenu d'un temps suit la visibilité de ce temps ;
     * - un document suit sa date propre quand il en a une, sinon son temps (voir
     *   LessonLogAttachment::isVisibleFor()) ;
     * - un travail suit sa propre publication, comme partout ailleurs dans l'application : il vit
     *   aussi dans « Travail à réaliser », le cacher ici ne le cacherait pas là-bas.
     *
     * Les enseignants de la formation et le personnel voient tout, y compris ce qui n'est pas
     * publié - c'est leur écran de travail. Le droit de modifier CETTE séance n'entre pas en
     * ligne de compte : un collègue qui relit la séance d'un autre n'est pas un étudiant.
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
                // La carte disparaît quand il n'y reste rien à lire : un temps masqué ne doit pas
                // laisser un cadre vide qui dit tout de même « il se passe quelque chose ici ».
                'shown' => !$restricted || $contentVisible || [] !== $attachments || [] !== $sectionWorks,
            ];
        }

        return $views;
    }

    /**
     * Le bandeau de séquence en tête du cahier de texte (maquette 2a) : où en est cette séance dans
     * la séquence qui la porte, et l'état des autres.
     *
     * Null quand la séance n'est rattachée à aucune progression - le bandeau disparaît alors, ce que
     * la maquette prévoit explicitement. Les séances de la séquence sont ordonnées par leur créneau
     * plutôt que par leur position déclarée : c'est l'ordre dans lequel la classe les vivra, et le
     * seul qui permette de dire « faite » ou « à venir ».
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
     * Ouvrir un document : la trace est posée ici, puis l'étudiant est renvoyé vers le fichier ou
     * le lien. Passer par l'application plutôt que de pointer le fichier directement est le seul
     * moyen de savoir qu'un support a été ouvert, et non seulement affiché dans une liste.
     *
     * Seules les ouvertures d'étudiants sont comptées : l'enseignant qui relit son propre cahier de
     * texte n'a pas à gonfler ses statistiques.
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

        // Le lien ne s'affiche pas tant que le document n'est pas publié, mais une adresse se
        // devine : sans ce contrôle, la visibilité ne tiendrait qu'au gabarit.
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
     * Le suivi d'ouverture des documents d'une séance : par document, et pour l'ensemble.
     *
     * L'ensemble ne compte que les étudiants ayant ouvert TOUS les documents - en avoir ouvert
     * trois sur quatre, c'est ne pas avoir tout lu, et une moyenne le masquerait.
     *
     * Le public est celui de la séance : ses options quand elle en porte - un TP de demi-groupe ne
     * concerne pas l'autre moitié -, sinon toute la formation.
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
     * Le suivi affiché sous chaque travail (maquette 2a, « lu par 19 / 24 ») : où en est le public
     * visé.
     *
     * Deux compteurs, deux natures de preuve :
     *  - « ouvert par », pour les travaux à lire : la page du travail a été ouverte, fait observé
     *    et daté que l'étudiant ne choisit pas de produire. C'est le suivi de lecture proprement
     *    dit, et il ne prétend pas dire que le texte a été lu - seulement qu'il a été affiché ;
     *  - « fait par », pour tout travail qui se solde par une déclaration : l'étudiant dit avoir
     *    fini. Moins fiable, mais c'est la seule chose qu'un exercice sur cahier puisse produire.
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
     * Les travaux de la séance, rangés par temps. Le « pendant » n'en accueille pas, mais la clé
     * existe pour que le gabarit puisse boucler sur les trois temps sans se poser la question.
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
     * Supprimer un travail donné. Geste délibéré, y compris quand des étudiants ont déjà déposé ou
     * déclaré avoir fini - l'import, lui, s'y refuse et les épargne. L'écran prévient plus
     * fermement dans ce cas, puisque la suppression emporte aussi leurs productions.
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
     * Reprendre la séance de bibliothèque dont ce créneau est issu : la première entrée du menu
     * d'import, et la seule qui remplace au lieu de compléter - la séance source fait autorité.
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

        // Dit ce qui a survécu à l'import plutôt que de laisser l'enseignant le découvrir : ces
        // travaux-là portent déjà des productions, et lui seul peut décider de les supprimer.
        if (0 < $kept) {
            $this->addFlash('info', $translator->trans('lessonLogImportKeptWorksMessage', ['%count%' => $kept]));
        }

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    /**
     * Reprendre le cahier de texte d'une autre séance (maquette 2a). La séance source doit être
     * comparable - même matière, autre formation - et l'enseignant doit pouvoir modifier la cible ;
     * il n'a en revanche pas à pouvoir modifier la source, qu'il ne fait que lire.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/import/{sourceId}', name: 'app_program_timetable_session_log_import', methods: ['POST'], requirements: ['sourceId' => '\d+'])]
    public function importFromSession(int $id, int $sessionId, int $sourceId, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogImporter $importer): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        if (!$this->isCsrfTokenValid('lesson_log_import', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $source = $lessonSessionRepository->find($sourceId) ?? throw $this->createNotFoundException();

        // La source doit figurer parmi les séances proposées : c'est ce qui garantit qu'elle porte
        // bien la même matière, et qu'on ne recopie pas n'importe quel cahier de texte de la base.
        $allowed = false;
        foreach ($importer->browsableFor($session) as $candidate) {
            $allowed = $allowed || $candidate->getId() === $source->getId();
        }

        if (!$allowed) {
            throw $this->createNotFoundException();
        }

        $importer->import($source, $session, $this->currentUser());

        $this->addFlash('success', 'lessonLogImportedFlashMessage');

        return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/attachments', name: 'app_program_timetable_session_log_attachments_new', methods: ['POST'])]
    public function addAttachment(int $id, int $sessionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, FileUploadService $fileUploadService): Response
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
            /** @var UploadedFile|null $file */
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
                // Le temps auquel rattacher le document vient du lien « + Ajouter » cliqué ; à
                // défaut, le contenu réalisé, seul endroit où les documents s'affichaient avant.
                $attachment->setSection(LessonLogSection::tryFrom((string) $request->request->get('section')) ?? LessonLogSection::During);

                if (null !== $file) {
                    $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
                    $key = $fileUploadService->upload(
                        self::ATTACHMENT_UPLOAD_PREFIX,
                        sprintf('%d-%d-%s.%s', $session->getId(), time(), bin2hex(random_bytes(4)), $extension),
                        $file,
                    );
                    $attachment->setType(LessonLogAttachmentSourceType::Upload);
                    $attachment->setStorageKey($key);
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

        // Champ nommé à part : le bouton est un formaction du formulaire des trois temps, qui a
        // déjà son propre _token.
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
