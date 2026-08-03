<?php

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonSession;
use App\Entity\ProgressionSeance;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\LessonLogAttachmentSourceType;
use App\Enum\LessonLogSection;
use App\Form\LessonLogAttachmentType;
use App\Form\LessonLogType;
use App\Form\LessonLogWorkType;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentRepository;
use App\Repository\LessonLogAttachmentRepository;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgressionSeancePlacementRepository;
use App\Service\SeanceContentResolver;
use App\Security\Voter\LessonLogVoter;
use App\Service\AssignmentAudienceResolver;
use App\Service\FileUploadService;
use App\Service\LessonLogImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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

    /**
     * Vue cours (design_handoff_cahier_de_texte 1b) : où en est le cahier de texte d'une formation,
     * séance par séance. Écran de navigation et de repérage des trous, pas d'édition - la saisie se
     * fait sur la page de séance, vers laquelle chaque ligne renvoie.
     */
    #[Route(path: '/programs/{id}/cahier-de-texte', name: 'app_program_lesson_logs')]
    public function courseView(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        $sessions = $lessonSessionRepository->findForProgram($program);
        usort($sessions, static fn (LessonSession $a, LessonSession $b): int => [$a->getDay(), $a->getStartHour()] <=> [$b->getDay(), $b->getStartHour()]);

        $logsBySessionId = [];
        foreach ($lessonLogRepository->findForProgram($program) as $log) {
            $logsBySessionId[$log->getLessonSession()?->getId()] = $log;
        }

        $rows = [];
        $filled = 0;
        foreach ($sessions as $session) {
            $log = $logsBySessionId[$session->getId()] ?? null;
            $state = $this->lessonLogState($log);
            $filled += 'filled' === $state ? 1 : 0;
            $rows[] = ['session' => $session, 'log' => $log, 'state' => $state];
        }

        // La séance mise en aperçu : celle demandée, sinon la première non remplie, sinon la
        // dernière - ce qu'un enseignant vient chercher en ouvrant cet écran.
        $selectedId = $request->query->getInt('seance');
        $selected = null;
        foreach ($rows as $row) {
            if ($row['session']->getId() === $selectedId) {
                $selected = $row;
            }
        }
        foreach ($rows as $row) {
            $selected ??= 'empty' === $row['state'] ? $row : null;
        }
        $selected ??= $rows[array_key_last($rows)] ?? null;

        return $this->render('program/lesson_logs.html.twig', [
            'program' => $program,
            'rows' => $rows,
            'filled' => $filled,
            'selected' => $selected,
            'sections' => LessonLogSection::cases(),
        ]);
    }

    /**
     * L'état d'un cahier de texte en un mot, ce que la pastille de la maquette résume : rempli
     * quand les trois temps disent quelque chose, partiel dès qu'un seul le dit, vide sinon.
     */
    private function lessonLogState(?LessonLog $log): string
    {
        if (null === $log) {
            return 'empty';
        }

        $filled = 0;
        foreach (LessonLogSection::cases() as $section) {
            $filled += '' !== trim(strip_tags((string) $log->getContent($section))) ? 1 : 0;
        }

        return match (true) {
            0 === $filled => 'empty',
            \count(LessonLogSection::cases()) === $filled => 'filled',
            default => 'partial',
        };
    }

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log', name: 'app_program_timetable_session_log', methods: ['GET', 'POST'])]
    public function show(int $id, int $sessionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonLogRepository $lessonLogRepository, AssignmentRepository $assignmentRepository, ProgressionSeancePlacementRepository $placementRepository, AssignmentCompletionRepository $completionRepository, AssignmentAudienceResolver $audienceResolver, LessonLogImporter $importer, SeanceContentResolver $seanceContentResolver): Response
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

        // Le travail en cours de création, s'il y en a un : le modal 2b est rendu par-dessus la
        // page plutôt que sur un écran à part, comme le panneau de l'inventaire.
        $workSection = LessonLogSection::tryFrom((string) $request->query->get('travail'));
        $workForm = null;
        $editedWork = null;
        if ($canEdit && 0 !== $request->query->getInt('modifier')) {
            // Modification : le modal est le même, ouvert sur un travail existant plutôt que vide.
            $editedWork = $assignmentRepository->find($request->query->getInt('modifier'));
            $editedWork = $editedWork?->getLessonSession()?->getId() === $session->getId() ? $editedWork : null;
            $workSection = $editedWork?->getLessonLogSection() ?? $workSection;
        }

        if ($canEdit && null !== $workSection && \in_array($workSection, LessonLogSection::acceptingWork(), true)) {
            $workForm = $this->buildWorkForm($session, $workSection, $editedWork)->createView();
        }

        return $this->render('program/lesson_log.html.twig', [
            'program' => $program,
            'session' => $session,
            'log' => $log,
            'form' => $form,
            'canEdit' => $canEdit,
            'attachmentForm' => $canEdit ? $this->createForm(LessonLogAttachmentType::class) : null,
            'sections' => LessonLogSection::cases(),
            'sequenceStrip' => $this->sequenceStrip($placementRepository, $session),
            'importSuggestions' => $canEdit ? $importer->suggestionsFor($session) : [],
            // La séance de bibliothèque dont ce créneau est issu, s'il l'est - première entrée du
            // menu d'import. Déjà résolue plus bas pour le pré-remplissage, réutilisée telle quelle.
            'importBrowsable' => $canEdit ? $importer->browsableFor($session) : [],
            'documentSection' => LessonLogSection::tryFrom((string) $request->query->get('document')),
            'natureHints' => array_combine(
                array_map(static fn (AssignmentNature $n): string => $n->value, AssignmentNature::cases()),
                array_map(static fn (AssignmentNature $n): string => $n->hintKey(), AssignmentNature::cases()),
            ),
            'worksBySection' => $works,
            'readTracking' => $canEdit ? $this->readTracking($works, $completionRepository, $audienceResolver) : [],
            // Les travaux déjà commencés par des étudiants : la suppression les prévient autrement.
            'worksWithProduction' => $canEdit ? $importer->worksWithProduction($session) : [],
            // Sert à ne demander confirmation que lorsque l'import écrase réellement quelque chose.
            'importHasContent' => $canEdit && $importer->hasContent($session),
            'workSection' => $workSection,
            'editedWork' => $editedWork,
            'workForm' => $workForm,
            // Only offered when it exists - see design/validated/teaching-sequence-library.md's
            // "relationship to part A". Part A fully works without part C ever being built.
            'seanceInstance' => $canEdit ? $seanceContentResolver->forLessonSession($session) : null,
        ]);
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
            $isCurrent = $row['seance']->getActivePlacements() !== [] && $this->placementsCover($row['seance'], $session);
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
     * Le suivi de lecture des travaux « À lire » (maquette 2a, « lu par 19 / 24 ») : combien
     * d'étudiants du public visé l'ont déclaré lu.
     *
     * Il n'y a pas d'autre trace de lecture que cette déclaration - rien ne dit qu'un document
     * ouvert a été lu - et c'est le même geste que le « Marquer comme fait » de l'écran étudiant.
     * Réservé aux travaux à lire : compter les copies rendues serait un autre suivi, que l'écran de
     * dépôt fait déjà.
     *
     * @param array<string, list<Assignment>> $worksBySection
     *
     * @return array<int, array{read: int, total: int}>
     */
    private function readTracking(array $worksBySection, AssignmentCompletionRepository $completionRepository, AssignmentAudienceResolver $audienceResolver): array
    {
        $readable = [];
        foreach ($worksBySection as $works) {
            foreach ($works as $work) {
                if (AssignmentNature::ToRead === $work->getNature()) {
                    $readable[] = $work;
                }
            }
        }

        $counts = $completionRepository->countByAssignment($readable);

        $tracking = [];
        foreach ($readable as $work) {
            $tracking[(int) $work->getId()] = [
                'read' => $counts[(int) $work->getId()] ?? 0,
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
            $works[$assignment->getLessonLogSection()?->value ?? LessonLogSection::After->value][] = $assignment;
        }

        return $works;
    }

    /**
     * Un travail donné en séance hérite du public de cette séance : ses options si elle en porte
     * (demi-groupe, spécialité), sinon toute la formation. L'enseignant n'a donc rien à choisir, et
     * un TP de demi-groupe ne s'affiche pas chez l'autre moitié.
     */
    private function buildWorkForm(LessonSession $session, LessonLogSection $section, ?Assignment $assignment = null): \Symfony\Component\Form\FormInterface
    {
        if (null === $assignment) {
            $assignment = new Assignment($session->getProgram());
            $assignment->setLessonSession($session);
            $assignment->setLessonLogSection($section);
            $assignment->setDueDate($this->defaultDueDate($session));

            foreach ($session->getOptions() as $option) {
                $assignment->addOption($option);
            }

            $assignment->setAudienceType($session->getOptions()->isEmpty() ? AssignmentAudienceType::Program : AssignmentAudienceType::Option);
        }

        $isEdit = null !== $assignment->getId();

        return $this->createForm(LessonLogWorkType::class, $assignment, [
            'program' => $session->getProgram(),
            'published' => $isEdit ? $assignment->isVisibleFor() : true,
            'action' => $isEdit
                ? $this->generateUrl('app_program_timetable_session_log_work_edit', [
                    'id' => $session->getProgram()->getId(),
                    'sessionId' => $session->getId(),
                    'assignmentId' => $assignment->getId(),
                ])
                : $this->generateUrl('app_program_timetable_session_log_work_new', [
                    'id' => $session->getProgram()->getId(),
                    'sessionId' => $session->getId(),
                    'section' => $section->value,
                ]),
        ]);
    }

    // « Prochaine séance » de la maquette, faute de la connaître ici : une semaine après celle-ci,
    // à son heure de début - le rythme hebdomadaire d'un emploi du temps.
    private function defaultDueDate(LessonSession $session): \DateTimeImmutable
    {
        return ($session->getStartAt() ?? new \DateTimeImmutable())->modify('+7 days');
    }

    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/travaux/{section}', name: 'app_program_timetable_session_log_work_new', methods: ['POST'])]
    public function addWork(int $id, int $sessionId, string $section, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        $lessonLogSection = LessonLogSection::tryFrom($section) ?? throw $this->createNotFoundException();
        $form = $this->buildWorkForm($session, $lessonLogSection);
        $form->handleRequest($request);

        // Un travail de type Quiz sans quiz désigné n'aurait rien à ouvrir : c'est la seule règle
        // que la nature impose, et elle se vérifie ici plutôt que dans le type de formulaire, qui
        // ne voit pas les autres champs au moment de se construire.
        if ($form->isSubmitted() && AssignmentNature::Quiz === $form->getData()->getNature() && null === $form->getData()->getQuizInstance()) {
            $form->get('quizInstance')->addError(new FormError($translator->trans('lessonLogWorkQuizRequiredMessage')));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Assignment $assignment */
            $assignment = $form->getData();
            $assignment->setVisibleAt($form->get('publishNow')->getData() ? new \DateTimeImmutable() : null);
            // Le quiz n'a de sens que pour la nature qui le demande : le champ reste dans le DOM
            // quand une autre nature est choisie, c'est ici qu'on le remet à sa place.
            if (AssignmentNature::Quiz !== $assignment->getNature()) {
                $assignment->setQuizInstance(null);
            }
            $this->stampAuditFields($assignment, false);

            $entityManager->persist($assignment);
            $entityManager->flush();

            $this->addFlash('success', 'lessonLogWorkCreatedFlashMessage');

            return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
        }

        // Un formulaire invalide revient dans son modal, rouvert sur le bon temps.
        return $this->redirectToRoute('app_program_timetable_session_log', [
            'id' => $program->getId(),
            'sessionId' => $session->getId(),
            'travail' => $lessonLogSection->value,
        ]);
    }

    /**
     * Modification d'un travail déjà donné. Même formulaire et même modal que la création : ce qui
     * change est l'objet visé et, avec lui, l'adresse d'envoi.
     *
     * Le travail reste rattaché au temps où il a été créé - le déplacer d'« avant » à « après »
     * serait un autre geste, que la maquette ne prévoit pas.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/travaux/{assignmentId}/modifier', name: 'app_program_timetable_session_log_work_edit', methods: ['POST'], requirements: ['assignmentId' => '\d+'])]
    public function editWork(int $id, int $sessionId, int $assignmentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, AssignmentRepository $assignmentRepository, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $session = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->denyAccessUnlessGranted(LessonLogVoter::EDIT, $session);

        $assignment = $assignmentRepository->find($assignmentId) ?? throw $this->createNotFoundException();
        if ($assignment->getLessonSession()?->getId() !== $session->getId()) {
            throw $this->createNotFoundException();
        }

        $section = $assignment->getLessonLogSection() ?? LessonLogSection::After;
        $form = $this->buildWorkForm($session, $section, $assignment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && AssignmentNature::Quiz === $form->getData()->getNature() && null === $form->getData()->getQuizInstance()) {
            $form->get('quizInstance')->addError(new FormError($translator->trans('lessonLogWorkQuizRequiredMessage')));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if (AssignmentNature::Quiz !== $assignment->getNature()) {
                $assignment->setQuizInstance(null);
            }

            // Publier ou dépublier : décocher retire le travail de la vue des étudiants sans le
            // supprimer, ce qui est le seul moyen de revenir sur une publication trop rapide.
            $assignment->setVisibleAt($form->get('publishNow')->getData() ? ($assignment->getVisibleAt() ?? new \DateTimeImmutable()) : null);
            $this->stampAuditFields($assignment, true);
            $entityManager->flush();

            $this->addFlash('success', 'lessonLogWorkUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_timetable_session_log', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
        }

        return $this->redirectToRoute('app_program_timetable_session_log', [
            'id' => $program->getId(),
            'sessionId' => $session->getId(),
            'modifier' => $assignment->getId(),
        ]);
    }

    /**
     * Supprimer un travail donné. Geste délibéré, y compris quand des étudiants ont déjà déposé ou
     * déclaré avoir fini - l'import, lui, s'y refuse et les épargne. L'écran prévient plus
     * fermement dans ce cas, puisque la suppression emporte aussi leurs productions.
     */
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/travaux/{assignmentId}/supprimer', name: 'app_program_timetable_session_log_work_remove', methods: ['POST'], requirements: ['assignmentId' => '\d+'])]
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
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/importer-bibliotheque', name: 'app_program_timetable_session_log_import_library', methods: ['POST'])]
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
    #[Route(path: '/programs/{id}/timetable/sessions/{sessionId}/log/importer/{sourceId}', name: 'app_program_timetable_session_log_import', methods: ['POST'], requirements: ['sourceId' => '\d+'])]
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
