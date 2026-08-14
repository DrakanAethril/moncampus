<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Evaluation;
use App\Entity\LessonSession;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SchoolYear;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EvaluationNature;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgressionRepository;
use App\Repository\ProgressionSeanceRepository;
use App\Repository\ProgressionSequenceRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SequenceInstanceRepository;
use App\Repository\TopicRepository;
use App\Security\Voter\ProgressionVoter;
use App\Service\JsonRequestPayload;
use App\Service\PostValue;
use App\Service\ProgressionBuilder;
use App\Service\ProgressionCalendarBuilder;
use App\Service\ProgressionEvaluationSelector;
use App\Service\ProgressionPlacementService;
use App\Service\SequenceInstanceRemover;
use App\Util\DurationFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Progression pédagogique module - design/design_handoff_progression.
 *
 * Screen map (the design's own numbering, kept in the route names so a screenshot can be traced
 * back to its action): 4a annual() · 4b month() · 3a manageList() · 3c create() · 5a show() ·
 * 2a placement(), with the 2b créneau picker served as JSON by slots().
 *
 * Teacher-scoped throughout: what a teacher may plan is what they own a Topic for (see
 * TopicRepository::findForTeacherInSchoolYear()), not what class they happen to be attached to.
 * Staff are let in via ProgressionVoter for support purposes, same as the library.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgressionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgressionRepository $progressionRepository,
        private readonly SchoolYearRepository $schoolYearRepository,
        private readonly TopicRepository $topicRepository,
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly SequenceInstanceRepository $sequenceInstanceRepository,
        private readonly ProgressionPlacementService $placementService,
        private readonly ProgressionBuilder $builder,
        private readonly ProgressionCalendarBuilder $calendarBuilder,
        private readonly ProgressionEvaluationSelector $evaluationSelector,
    ) {
    }

    // 4a - the landing screen of the profile-menu link: ten months in horizontal columns, scrolled
    // to the current one.
    #[Route(path: '/progression', name: 'app_progression')]
    public function annual(Request $request): Response
    {
        $schoolYear = $this->currentSchoolYear();
        $teacher = $this->currentUser();
        $today = new \DateTimeImmutable('today');

        $progressions = $this->calendarBuilder->progressionsFor($teacher, $schoolYear);
        $filters = $this->readFilters($request);

        return $this->render('progression/annual.html.twig', [
            'months' => $this->calendarBuilder->annual($teacher, $schoolYear, $filters['cohortIds'], $filters, $today),
            'filterOptions' => $this->calendarBuilder->filterOptions($progressions),
            'filters' => $filters,
        ]);
    }

    // 4b - one month, weeks in columns.
    #[Route(path: '/progression/month/{month}', name: 'app_progression_month', requirements: ['month' => '\d{4}-\d{2}'])]
    public function month(string $month, Request $request): Response
    {
        $schoolYear = $this->currentSchoolYear();
        $teacher = $this->currentUser();
        $current = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00')
            ?: throw $this->createNotFoundException();

        $progressions = $this->calendarBuilder->progressionsFor($teacher, $schoolYear);
        $filters = $this->readFilters($request);

        return $this->render('progression/month.html.twig', [
            'weeks' => $this->calendarBuilder->month($teacher, $schoolYear, $current, $filters['cohortIds'], $filters),
            'filterOptions' => $this->calendarBuilder->filterOptions($progressions),
            'filters' => $filters,
            'month' => $current,
            'previousMonth' => $current->modify('-1 month')->format('Y-m'),
            'nextMonth' => $current->modify('+1 month')->format('Y-m'),
        ]);
    }

    // 3a - one row per classe × matière, with its hour volume and D/F/S counters.
    #[Route(path: '/progression/management', name: 'app_progression_manage')]
    public function manageList(Request $request): Response
    {
        $schoolYear = $this->currentSchoolYear();
        $progressions = $this->progressionRepository->findForTeacher($this->currentUser(), $schoolYear);

        $cohortFilter = $request->query->get('cohort');

        $rows = [];

        foreach ($progressions as $progression) {
            $rows[] = [
                'progression' => $progression,
                'cohort' => $progression->getProgram()?->getCohort(),
                'topic' => $progression->getTopic(),
                'counts' => $progression->getEvaluationCountsByNature(),
            ];
        }

        $chips = $this->cohortChips($rows);

        if (null !== $cohortFilter && '' !== $cohortFilter) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row['cohort']?->getId() ?? '') === $cohortFilter,
            ));
        }

        return $this->render('progression/manage_list.html.twig', [
            'rows' => $rows,
            'chips' => $chips,
            'selectedCohort' => $cohortFilter,
            'total' => \count($progressions),
        ]);
    }

    // 3c - the full-page creation form. The matière list is deliberately restricted to Topics this
    // teacher owns that have no progression yet ("couples sans progression uniquement").
    #[Route(path: '/progression/new', name: 'app_progression_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $schoolYear = $this->currentSchoolYear();
        $teacher = $this->currentUser();
        $candidates = $this->availableTopics($teacher, $schoolYear);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('progression_new', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $topic = $this->pickTopic($candidates, $request->request->getInt('topic'));
            $progression = new Progression($topic, $teacher);
            $this->entityManager->persist($progression);

            foreach ($this->readSequenceRows($request) as $row) {
                $instance = $row['instance'];
                if (!$this->isOwnSequenceInstance($progression, $instance)) {
                    // A séquence instantiated for another class, or by a colleague, has no business
                    // in this progression - re-checked here rather than trusted from the posted ids.
                    continue;
                }

                $this->builder->addSequence($progression, $instance, $row['startDate'], $row['placeInTimetable']);
            }

            $this->entityManager->flush();
            $this->placementService->replan($progression);
            $this->entityManager->flush();

            $this->addFlash('success', 'progressionCreatedFlashMessage');

            $first = $progression->getSequences()->first();

            return $first instanceof ProgressionSequence
                ? $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $first->getId()])
                : $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
        }

        return $this->render('progression/new.html.twig', [
            'candidates' => $candidates,
            // Built here rather than folded in Twig: a Twig `merge` on a hash whose keys are the
            // integer cohort ids reindexes them like array_merge does, which silently turned every
            // chip's data-cohort-id into 0..n and stopped it matching the topic rows.
            'cohorts' => $this->distinctCohorts($candidates),
            'hoursByTopicId' => $this->hoursByTopicId($candidates),
        ]);
    }

    // 5a - the progression itself: the ordered list of its séquences.
    #[Route(path: '/progression/{id}', name: 'app_progression_show', requirements: ['id' => '\d+'])]
    public function show(int $id, ProgressionSequenceRepository $sequenceRepository): Response
    {
        $progression = $this->findOrDeny($id);

        return $this->render('progression/show.html.twig', [
            'progression' => $progression,
            'sequences' => $sequenceRepository->findOrderedForProgression($progression),
            'availableSequenceInstances' => $this->unusedSequenceInstances($progression),
            'counts' => $progression->getEvaluationCountsByNature(),
            'outOfSequenceEvaluations' => $this->evaluationSelector->outOfSequence($progression->getTopic()?->getEvaluations() ?? []),
            'currentMonthKey' => (new \DateTimeImmutable('today'))->format('Y-m'),
        ]);
    }

    // 2a - placing ONE séquence's séances on real créneaux.
    #[Route(path: '/progression/{id}/sequence/{sequenceId}/placement', name: 'app_progression_placement', requirements: ['id' => '\d+', 'sequenceId' => '\d+'])]
    public function placement(int $id, int $sequenceId, ProgressionSeanceRepository $seanceRepository): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);

        return $this->render('progression/placement.html.twig', [
            'progression' => $progression,
            'sequence' => $sequence,
            'seances' => $seanceRepository->findOrderedForSequence($sequence),
            'evaluation_natures' => EvaluationNature::cases(),
            // The evaluations this séquence carries, so that attaching one to a séquence no longer
            // takes it out of every screen the module has.
            'evaluations' => $this->evaluationSelector->forSequence($progression->getTopic()?->getEvaluations() ?? [], $sequence),
            // The edit form can move an evaluation to another séquence, so it needs them all.
            'sequences' => $progression->getSequences(),
        ]);
    }

    #[Route(path: '/progression/{id}/sequences/reorder', name: 'app_progression_sequences_reorder', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reorderSequences(int $id, Request $request): JsonResponse
    {
        $progression = $this->findOrDeny($id);
        $this->assertJsonCsrf($request, 'progression_sequences_reorder');

        $this->builder->reorderSequences($progression, JsonRequestPayload::fromRequest($request)->ids());
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route(path: '/progression/{id}/sequence/{sequenceId}/sessions/reorder', name: 'app_progression_seances_reorder', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+'])]
    public function reorderSeances(int $id, int $sequenceId, Request $request): JsonResponse
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);
        $this->assertJsonCsrf($request, 'progression_seances_reorder');

        $this->builder->reorderSeances($sequence, JsonRequestPayload::fromRequest($request)->ids());
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route(path: '/progression/{id}/sequences/add', name: 'app_progression_sequence_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addSequence(int $id, Request $request): Response
    {
        $progression = $this->findOrDeny($id);

        if (!$this->isCsrfTokenValid('progression_sequence_add', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $instance = $this->sequenceInstanceRepository->find((int) $request->request->get('sequenceInstance'))
            ?? throw $this->createNotFoundException();

        if (!$this->isOwnSequenceInstance($progression, $instance)) {
            throw $this->createNotFoundException();
        }

        $this->builder->addSequence($progression, $instance);
        $this->entityManager->flush();
        $this->placementService->replan($progression);
        $this->entityManager->flush();

        $this->addFlash('success', 'progressionSequenceAddedFlashMessage');

        return $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
    }

    #[Route(path: '/progression/{id}/sequence/{sequenceId}/remove', name: 'app_progression_sequence_remove', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+'])]
    public function removeSequence(int $id, int $sequenceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);

        if (!$this->isCsrfTokenValid('progression_sequence_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->builder->removeSequence($sequence);
        $this->entityManager->flush();

        $this->addFlash('success', 'progressionSequenceRemovedFlashMessage');

        return $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
    }

    /**
     * The rail's "séquences non affectées" block: deletes the instantiation itself, not a row of the
     * progression - the séquence copied for this class goes away entirely and stops being offered by
     * "+ Ajouter une séquence".
     *
     * Same service as the admin screen (App\Service\SequenceInstanceRemover), so a séquence that
     * another progression of the same Program had planned is unplanned and its créneaux freed rather
     * than left dangling. That is also why the confirm() spells the consequences out: the frozen copy
     * cannot be rebuilt from the library template, which may have moved on since.
     *
     * A teacher only ever deletes their own copies - deleting a colleague's is the admin screen's
     * job (/programs/{id}/sequences), which is ROLE_ADMIN precisely because it reaches the whole
     * class's pool.
     */
    #[Route(path: '/progression/{id}/sequence-instances/{sequenceInstanceId}/remove', name: 'app_progression_sequence_instance_remove', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceInstanceId' => '\d+'])]
    public function removeSequenceInstance(int $id, int $sequenceInstanceId, Request $request, SequenceInstanceRemover $remover): Response
    {
        $progression = $this->findOrDeny($id);

        if (!$this->isCsrfTokenValid('progression_sequence_instance_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $instance = $this->sequenceInstanceRepository->find($sequenceInstanceId) ?? throw $this->createNotFoundException();

        // Re-checked against the progression rather than trusted from the id, same as addSequence().
        if (!$this->isOwnSequenceInstance($progression, $instance)) {
            throw $this->createNotFoundException();
        }

        $remover->remove($instance);

        $this->addFlash('success', 'progressionSequenceInstanceRemovedFlashMessage');

        return $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
    }

    // The "Placer dans l'EDT" checkbox and the "À partir de" date of a séquence row, both of which
    // change the whole downstream layout - hence the replan.
    #[Route(path: '/progression/{id}/sequence/{sequenceId}/settings', name: 'app_progression_sequence_settings', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+'])]
    public function updateSequenceSettings(int $id, int $sequenceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);

        if (!$this->isCsrfTokenValid('progression_sequence_settings', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $sequence->setPlaceInTimetable($request->request->getBoolean('placeInTimetable'));
        $sequence->setForcedStartDate($this->readDate($request->request->getString('forcedStartDate')));

        $this->placementService->replan($progression);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
    }

    #[Route(path: '/progression/{id}/sequence/{sequenceId}/validate', name: 'app_progression_placement_validate', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+'])]
    public function validatePlacement(int $id, int $sequenceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);

        if (!$this->isCsrfTokenValid('progression_placement_validate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->placementService->validate($sequence);
        $this->entityManager->flush();

        $this->addFlash('success', 'progressionPlacementValidatedFlashMessage');

        return $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
    }

    #[Route(path: '/progression/{id}/sequence/{sequenceId}/reassociate', name: 'app_progression_placement_reassociate', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+'])]
    public function reassociate(int $id, int $sequenceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);

        if (!$this->isCsrfTokenValid('progression_placement_reassociate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->placementService->reassociate($sequence);
        $this->entityManager->flush();

        $this->addFlash('success', 'progressionPlacementReassociatedFlashMessage');

        return $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $sequence->getId()]);
    }

    // Removing a séance frees its créneau but keeps the row so "Rétablir" can undo it - the design
    // is explicit that the séance type stays in the séquence either way.
    #[Route(path: '/progression/{id}/sequence/{sequenceId}/session/{seanceId}/toggle', name: 'app_progression_seance_toggle', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+', 'seanceId' => '\d+'])]
    public function toggleSeance(int $id, int $sequenceId, int $seanceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);
        $seance = $this->findSeanceOrDeny($sequence, $seanceId);

        if (!$this->isCsrfTokenValid('progression_seance_toggle', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $seance->setRemoved(!$seance->isRemoved());
        if ($seance->isRemoved()) {
            $seance->clearPlacements();
        }

        $this->placementService->replan($progression);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $sequence->getId()]);
    }

    #[Route(path: '/progression/{id}/sequence/{sequenceId}/sessions/add', name: 'app_progression_seance_add', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+'])]
    public function addSeance(int $id, int $sequenceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);

        if (!$this->isCsrfTokenValid('progression_seance_add', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $title = trim((string) $request->request->get('title'));
        if ('' === $title) {
            $this->addFlash('danger', 'progressionSeanceTitleRequiredFlashMessage');

            return $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $sequence->getId()]);
        }

        $this->builder->addAdHocSeance(
            $sequence,
            $title,
            $this->readMinutes($request->request->getString('duration')),
            EvaluationNature::tryFrom((string) $request->request->get('evaluationNature')),
        );
        $this->entityManager->flush();
        $this->placementService->replan($progression);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $sequence->getId()]);
    }

    // Backs the 2b modal: every créneau of this progression's matière, with what already sits on
    // it. Nothing is filtered out - the design has no notion of slot availability, a busy slot is
    // simply labelled as such.
    #[Route(path: '/progression/{id}/sequence/{sequenceId}/session/{seanceId}/slots', name: 'app_progression_seance_slots', requirements: ['id' => '\d+', 'sequenceId' => '\d+', 'seanceId' => '\d+'])]
    public function slots(int $id, int $sequenceId, int $seanceId): JsonResponse
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);
        $seance = $this->findSeanceOrDeny($sequence, $seanceId);
        $topic = $progression->getTopic();

        $taken = [];
        foreach ($progression->getSequences() as $other) {
            foreach ($other->getSeances() as $otherSeance) {
                if ($otherSeance->isRemoved() || $otherSeance === $seance) {
                    continue;
                }
                foreach ($otherSeance->getActivePlacements() as $placement) {
                    $taken[(int) $placement->getLessonSession()?->getId()] = $otherSeance->getTitle();
                }
            }
        }

        $selected = [];
        foreach ($seance->getActivePlacements() as $placement) {
            $selected[] = (int) $placement->getLessonSession()?->getId();
        }

        // Durations are shipped already rendered ("55 min", "1 h 30") rather than as a number the
        // JavaScript would suffix itself: a créneau is measured in decimal hours and a séance in
        // minutes, and re-deriving that split client-side is exactly how the two units got mixed up
        // in the first place.
        $slots = array_map(
            static function (LessonSession $session) use ($taken): array {
                $id = (int) $session->getId();
                $start = $session->getStartHour();
                $end = $session->getEndHour();

                return [
                    'id' => $id,
                    'day' => $session->getDay()?->format('Y-m-d'),
                    'start' => $start?->format('H:i'),
                    'end' => $end?->format('H:i'),
                    'room' => $session->getClassRoom()?->getName(),
                    'duration' => DurationFormatter::minutes((int) round(60 * (float) ($session->getLength() ?? '0'))),
                    'takenBy' => $taken[$id] ?? null,
                ];
            },
            null === $topic ? [] : $this->lessonSessionRepository->findOrderedForTopic($topic),
        );

        return $this->json([
            'seance' => [
                'id' => $seance->getId(),
                'title' => $seance->getTitle(),
                'duration' => DurationFormatter::minutes($seance->getPlannedMinutesOrZero()),
            ],
            'selected' => $selected,
            'slots' => $slots,
        ]);
    }

    // The 2b modal's submit: Dupliquer / Scinder over the checked créneaux.
    #[Route(path: '/progression/{id}/sequence/{sequenceId}/session/{seanceId}/associate', name: 'app_progression_seance_associate', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+', 'seanceId' => '\d+'])]
    public function associate(int $id, int $sequenceId, int $seanceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);
        $seance = $this->findSeanceOrDeny($sequence, $seanceId);

        if (!$this->isCsrfTokenValid('progression_seance_associate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $topic = $progression->getTopic();
        $eligible = [];
        foreach (null === $topic ? [] : $this->lessonSessionRepository->findOrderedForTopic($topic) as $session) {
            $eligible[(int) $session->getId()] = $session;
        }

        // Re-resolved against the matière's own créneaux rather than trusted from the ids - same
        // reasoning as LaptopController::resolveActiveBorrower().
        $picked = [];
        foreach ($request->request->all('sessions') as $sessionId) {
            $eligibleId = (int) $this->scalar($sessionId);
            if (isset($eligible[$eligibleId])) {
                $picked[] = $eligible[$eligibleId];
            }
        }

        if ([] === $picked) {
            $this->addFlash('danger', 'progressionNoSlotPickedFlashMessage');

            return $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $sequence->getId()]);
        }

        $mode = 'duplicate' === $request->request->get('mode') ? 'duplicate' : 'split';
        $this->placementService->associate($seance, $picked, $mode, $this->readMinutes($request->request->getString('duration')));
        $this->entityManager->flush();

        return $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $sequence->getId()]);
    }

    // "Ou : ramener la séance à X h (durée du créneau)".
    #[Route(path: '/progression/{id}/sequence/{sequenceId}/session/{seanceId}/fit', name: 'app_progression_seance_fit', methods: ['POST'], requirements: ['id' => '\d+', 'sequenceId' => '\d+', 'seanceId' => '\d+'])]
    public function fitToSlot(int $id, int $sequenceId, int $seanceId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $sequence = $this->findSequenceOrDeny($progression, $sequenceId);
        $seance = $this->findSeanceOrDeny($sequence, $seanceId);

        if (!$this->isCsrfTokenValid('progression_seance_fit', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $topic = $progression->getTopic();
        $sessionId = (int) $request->request->get('session');
        $session = null;
        foreach (null === $topic ? [] : $this->lessonSessionRepository->findOrderedForTopic($topic) as $candidate) {
            if ((int) $candidate->getId() === $sessionId) {
                $session = $candidate;
                break;
            }
        }

        if (null === $session) {
            throw $this->createNotFoundException();
        }

        $this->placementService->fitToSlot($seance, $session);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_progression_placement', ['id' => $progression->getId(), 'sequenceId' => $sequence->getId()]);
    }

    // "+ Poser une évaluation" on 5a. Writes a real Carnet de notes row carrying a nature - there
    // is no separate progression-evaluation table, so a sommative posed here is immediately the
    // one the gradebook will collect marks on.
    #[Route(path: '/progression/{id}/evaluations/add', name: 'app_progression_evaluation_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addEvaluation(int $id, Request $request): Response
    {
        $progression = $this->findOrDeny($id);

        if (!$this->isCsrfTokenValid('progression_evaluation_add', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $nature = EvaluationNature::tryFrom((string) $request->request->get('nature'))
            ?? throw $this->createNotFoundException();
        $date = $this->readDateTime($request->request->getString('date'), $request->request->getString('time'));
        $name = trim((string) $request->request->get('name'));

        if ('' === $name || null === $date) {
            $this->addFlash('danger', 'progressionEvaluationIncompleteFlashMessage');

            return $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
        }

        /** @var Topic $topic */
        $topic = $progression->getTopic();

        $evaluation = new Evaluation($topic, $name, $date);
        $evaluation->setNature($nature);
        // AuditableTrait's created_by_id is NOT NULL and never auto-filled - same explicit call as
        // ProgramGradebookController::evaluationForm().
        $evaluation->setCreatedBy($this->currentUser());
        $sequence = $this->readEvaluationSequence($progression, PostValue::nullableInt($request, 'sequence'));
        $evaluation->setProgressionSequence($sequence);

        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();

        $this->addFlash('success', 'progressionEvaluationAddedFlashMessage');

        return $this->afterEvaluation($progression, $sequence);
    }

    /**
     * Editing one, from wherever it is listed: 5a for a hors-séquence evaluation, 2a for one a
     * séquence carries. Both post here and both land back on the screen the evaluation now belongs
     * to, which is not necessarily the one they came from - moving it to a séquence moves the row.
     */
    #[Route(path: '/progression/{id}/evaluations/{evaluationId}/edit', name: 'app_progression_evaluation_edit', methods: ['POST'], requirements: ['id' => '\d+', 'evaluationId' => '\d+'])]
    public function editEvaluation(int $id, int $evaluationId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $evaluation = $this->findEvaluationOrDeny($progression, $evaluationId);

        if (!$this->isCsrfTokenValid('progression_evaluation_edit', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $nature = EvaluationNature::tryFrom((string) $request->request->get('nature'))
            ?? throw $this->createNotFoundException();
        $date = $this->readDateTime($request->request->getString('date'), $request->request->getString('time'));
        $name = trim((string) $request->request->get('name'));

        if ('' === $name || null === $date) {
            $this->addFlash('danger', 'progressionEvaluationIncompleteFlashMessage');

            return $this->afterEvaluation($progression, $evaluation->getProgressionSequence());
        }

        $evaluation->setName($name);
        $evaluation->setNature($nature);
        $evaluation->setDate($date);
        $sequence = $this->readEvaluationSequence($progression, PostValue::nullableInt($request, 'sequence'));
        $evaluation->setProgressionSequence($sequence);

        $this->entityManager->flush();

        $this->addFlash('success', 'progressionEvaluationUpdatedFlashMessage');

        return $this->afterEvaluation($progression, $sequence);
    }

    /**
     * Deactivation, not a DELETE - the same thing the Carnet de notes' own ✕ does
     * (ProgramGradebookController::deactivateEvaluation()), and for the same reason: an evaluation
     * may already carry marks, and this screen must not be a way to destroy them. It leaves both
     * progression lists (App\Service\ProgressionEvaluationSelector skips inactive rows) and the
     * gradebook at once.
     */
    #[Route(path: '/progression/{id}/evaluations/{evaluationId}/remove', name: 'app_progression_evaluation_remove', methods: ['POST'], requirements: ['id' => '\d+', 'evaluationId' => '\d+'])]
    public function removeEvaluation(int $id, int $evaluationId, Request $request): Response
    {
        $progression = $this->findOrDeny($id);
        $evaluation = $this->findEvaluationOrDeny($progression, $evaluationId);

        if (!$this->isCsrfTokenValid('progression_evaluation_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $sequence = $evaluation->getProgressionSequence();

        $evaluation->setInactiveDate(new \DateTimeImmutable());
        $evaluation->setInactivatedBy($this->currentUser());
        $this->entityManager->flush();

        $this->addFlash('success', 'progressionEvaluationRemovedFlashMessage');

        return $this->afterEvaluation($progression, $sequence);
    }

    // The 3c autocompletion - restricted to the teacher's own séquences instantiated for THIS class
    // and year, never the library templates (the design says so twice, in §3 and on the field's own
    // hint) and never a colleague's copy, same rule as 5a's "+ Ajouter une séquence".
    #[Route(path: '/progression/sequences-search', name: 'app_progression_sequences_search')]
    public function sequencesSearch(Request $request): JsonResponse
    {
        $schoolYear = $this->currentSchoolYear();
        $teacher = $this->currentUser();
        $topicId = (int) $request->query->get('topic');

        $topic = null;
        foreach ($this->topicRepository->findForTeacherInSchoolYear($teacher, $schoolYear) as $candidate) {
            if ((int) $candidate->getId() === $topicId) {
                $topic = $candidate;
                break;
            }
        }

        if (null === $topic || null === $topic->getProgram()) {
            return $this->json(['results' => []]);
        }

        $query = mb_strtolower(trim((string) $request->query->get('q', '')));
        $results = [];

        foreach ($this->sequenceInstanceRepository->findForProgramCreatedBy($topic->getProgram(), $teacher) as $instance) {
            $title = $instance->getTitre() ?? '';
            if ('' !== $query && !str_contains(mb_strtolower($title), $query)) {
                continue;
            }

            // SeanceInstance::$duree is a minute count, so this sum is minutes too - rendered here
            // rather than in progression_new_controller.js, same reasoning as slots() below.
            $minutes = 0;
            foreach ($instance->getSeanceInstances() as $seance) {
                $minutes += (int) round((float) ($seance->getDuree() ?? '0'));
            }

            $results[] = [
                'id' => $instance->getId(),
                'text' => $title,
                'duration' => DurationFormatter::minutes($minutes),
                'seances' => $instance->getSeanceInstances()->count(),
            ];
        }

        return $this->json(['results' => \array_slice($results, 0, 20)]);
    }

    /** @return list<Topic> the teacher's matières that don't have a progression yet */
    private function availableTopics(User $teacher, SchoolYear $schoolYear): array
    {
        return array_values(array_filter(
            $this->topicRepository->findForTeacherInSchoolYear($teacher, $schoolYear),
            fn (Topic $topic): bool => null === $this->progressionRepository->findOneForTopic($topic),
        ));
    }

    /**
     * The "· 48 h" figure next to a matière on 3a and 3c: what the timetable actually allocates
     * to it. Queried once per Program rather than once per Topic - findHoursByTopicForProgram()
     * already returns the whole Program's breakdown.
     *
     * @param list<Topic> $topics
     *
     * @return array<int, float> Topic id => hours
     */
    private function hoursByTopicId(array $topics): array
    {
        $hours = [];
        $done = [];

        foreach ($topics as $topic) {
            $program = $topic->getProgram();
            $programId = (int) ($program?->getId() ?? 0);
            if (null === $program || isset($done[$programId])) {
                continue;
            }

            $done[$programId] = true;
            foreach ($this->lessonSessionRepository->findHoursByTopicForProgram($program) as $topicId => $topicHours) {
                $hours[$topicId] = $topicHours;
            }
        }

        return $hours;
    }

    /**
     * The class chips of 3c step 1, in the design's alphabetical order.
     *
     * @param list<Topic> $topics
     *
     * @return list<array{id: int, label: string}>
     */
    private function distinctCohorts(array $topics): array
    {
        $cohorts = [];
        foreach ($topics as $topic) {
            $cohort = $topic->getProgram()?->getCohort();
            if (null !== $cohort) {
                $cohorts[(int) $cohort->getId()] = ['id' => (int) $cohort->getId(), 'label' => $cohort->getName()];
            }
        }

        usort($cohorts, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return $cohorts;
    }

    /** @param list<Topic> $candidates */
    private function pickTopic(array $candidates, int $topicId): Topic
    {
        foreach ($candidates as $topic) {
            if ($topic->getId() === $topicId) {
                return $topic;
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * The repeated rows of 3c step 5, as posted by the form: sequence instance + "Placer dans
     * l'EDT" + "À partir de".
     *
     * @return list<array{instance: SequenceInstance, startDate: \DateTimeImmutable|null, placeInTimetable: bool}>
     */
    private function readSequenceRows(Request $request): array
    {
        $ids = (array) $request->request->all('sequences');
        $startDates = (array) $request->request->all('sequenceStartDates');
        $placed = (array) $request->request->all('sequencePlaced');

        $rows = [];
        foreach ($ids as $position => $id) {
            $instance = $this->sequenceInstanceRepository->find((int) $this->scalar($id));
            if (null === $instance) {
                continue;
            }

            $rows[] = [
                'instance' => $instance,
                'startDate' => $this->readDate($this->scalar($startDates[$position] ?? null)),
                // An unchecked checkbox posts nothing, so the parallel array carries an explicit
                // "0"/"1" per row rather than relying on presence.
                'placeInTimetable' => '0' !== $this->scalar($placed[$position] ?? '1'),
            ];
        }

        return $rows;
    }

    /**
     * The "+ Ajouter une séquence" choices on 5a: séquences instantiated for this class and year
     * that the progression doesn't already carry. Library templates never appear here - only
     * SequenceInstances, per README §1.
     *
     * The rail's "séquences non affectées" block lists the same set on purpose: what it shows is
     * exactly what the add form offers, so a séquence is either in the progression or in that block,
     * never in neither.
     *
     * Narrowed to the progression's own teacher rather than to whoever is looking: the class's pool
     * is shared between its teachers (see SequenceInstanceRepository::findForProgramCreatedBy()),
     * and a staff member opening someone else's progression through ProgressionVoter's bypass has to
     * see that teacher's séquences - their own would be an empty list on a class they don't teach.
     *
     * @return list<SequenceInstance>
     */
    private function unusedSequenceInstances(Progression $progression): array
    {
        $program = $progression->getProgram();
        $teacher = $progression->getTeacher();
        if (null === $program || null === $teacher) {
            return [];
        }

        $used = [];
        foreach ($progression->getSequences() as $sequence) {
            $used[(int) $sequence->getSequenceInstance()?->getId()] = true;
        }

        return array_values(array_filter(
            $this->sequenceInstanceRepository->findForProgramCreatedBy($program, $teacher),
            static fn (SequenceInstance $instance): bool => !isset($used[(int) $instance->getId()]),
        ));
    }

    /**
     * The write side of unusedSequenceInstances(): may this progression plan, or delete, this
     * séquence instance?
     *
     * Every action that takes a SequenceInstance id from a request goes through it, because the id
     * is the only thing a hand-built POST has to change to reach a colleague's copy - filtering the
     * <select> alone would make the rule cosmetic.
     */
    private function isOwnSequenceInstance(Progression $progression, SequenceInstance $instance): bool
    {
        $teacher = $progression->getTeacher();

        return null !== $teacher
            && $instance->getProgram() === $progression->getProgram()
            && $instance->getCreatedBy() === $teacher;
    }

    /**
     * The séquence an evaluation is attached to, or null for the design's hors-séquence case.
     *
     * Takes a nullable id because "hors séquence" is the form's `<option value="">`: read with
     * InputBag::getInt() that empty string threw a 400 instead of meaning "none", which made posing
     * an evaluation on the progression itself impossible - see App\Service\PostValue.
     */
    private function readEvaluationSequence(Progression $progression, ?int $sequenceId): ?ProgressionSequence
    {
        if (null === $sequenceId) {
            return null;
        }

        foreach ($progression->getSequences() as $sequence) {
            if ($sequence->getId() === $sequenceId) {
                return $sequence;
            }
        }

        return null;
    }

    /**
     * An evaluation this progression may act on: one of its own matière's, still active.
     *
     * Scoped through the Topic rather than through EvaluationVoter::MANAGE alone, because the
     * question here is narrower than "may this user manage it" - a teacher holds several matières,
     * and an evaluation of another one has no business being edited from this progression's screens
     * even though the voter would allow it.
     */
    private function findEvaluationOrDeny(Progression $progression, int $evaluationId): Evaluation
    {
        foreach ($progression->getTopic()?->getEvaluations() ?? [] as $evaluation) {
            if ($evaluation->getId() === $evaluationId && null === $evaluation->getInactiveDate()) {
                return $evaluation;
            }
        }

        throw $this->createNotFoundException();
    }

    // Where an evaluation lives once written: on its séquence's placement screen when it has one,
    // on the progression itself otherwise. Both add/edit/remove use this so the teacher always ends
    // up looking at the list that now holds the row.
    private function afterEvaluation(Progression $progression, ?ProgressionSequence $sequence): Response
    {
        if (null === $sequence) {
            return $this->redirectToRoute('app_progression_show', ['id' => $progression->getId()]);
        }

        return $this->redirectToRoute('app_progression_placement', [
            'id' => $progression->getId(),
            'sequenceId' => $sequence->getId(),
        ]);
    }

    /**
     * @param list<array{cohort: \App\Entity\Cohort|null}> $rows
     *
     * @return list<array{id: string, label: string, count: int}>
     */
    private function cohortChips(array $rows): array
    {
        $chips = [];
        foreach ($rows as $row) {
            $cohort = $row['cohort'];
            if (null === $cohort) {
                continue;
            }

            $key = (string) $cohort->getId();
            $chips[$key] ??= ['id' => $key, 'label' => $cohort->getName(), 'count' => 0];
            ++$chips[$key]['count'];
        }

        usort($chips, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return $chips;
    }

    /**
     * @return array{cohortIds: list<int>, topicId: int|null, nature: EvaluationNature|null, withEvaluation: bool}
     */
    private function readFilters(Request $request): array
    {
        $evaluationFilter = $request->query->getString('evaluations');

        return [
            'cohortIds' => array_values(array_filter(array_map('intval', (array) $request->query->all('cohorts')))),
            'topicId' => '' === $request->query->getString('topic') ? null : $request->query->getInt('topic'),
            'nature' => EvaluationNature::tryFrom($evaluationFilter),
            'withEvaluation' => 'any' === $evaluationFilter,
        ];
    }

    /**
     * Rows of a repeated form arrive as parallel arrays whose values are unchecked - a hand-built
     * request can put anything in them. Non-scalars read as absent rather than raising.
     */
    private function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function readDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        return '' === $value ? null : (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value.' 00:00:00') ?: null);
    }

    /**
     * An evaluation's moment: Evaluation::$date is a DATETIME, so the hour it is sat at is stored in
     * the same column and needs no schema of its own.
     *
     * The time stays optional - an evaluation planned for a day but not yet for an hour is a normal
     * state, and it keeps midnight, which is what every row written before this field existed
     * carries. A `<input type="time">` posts "H:i", but some browsers add the seconds, so both are
     * accepted rather than trusting the shorter one.
     */
    private function readDateTime(string $date, string $time): ?\DateTimeImmutable
    {
        $day = $this->readDate($date);
        $time = trim($time);

        if (null === $day || '' === $time) {
            return $day;
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d '.$format, $day->format('Y-m-d').' '.$time);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return $day;
    }

    // Durations are posted in minutes throughout this module - see
    // ProgressionSeance::$plannedMinutes for why.
    private function readMinutes(string $value): ?int
    {
        $value = trim($value);

        return '' === $value || !is_numeric($value) ? null : max(0, (int) round((float) $value));
    }

    private function assertJsonCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function findOrDeny(int $id): Progression
    {
        $progression = $this->progressionRepository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(ProgressionVoter::EDIT, $progression);

        return $progression;
    }

    private function findSequenceOrDeny(Progression $progression, int $sequenceId): ProgressionSequence
    {
        foreach ($progression->getSequences() as $sequence) {
            if ((int) $sequence->getId() === $sequenceId) {
                return $sequence;
            }
        }

        throw $this->createNotFoundException();
    }

    private function findSeanceOrDeny(ProgressionSequence $sequence, int $seanceId): ProgressionSeance
    {
        foreach ($sequence->getSeances() as $seance) {
            if ((int) $seance->getId() === $seanceId) {
                return $seance;
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentSchoolYear(): SchoolYear
    {
        return $this->schoolYearRepository->findCurrentOrMostRecent()
            ?? throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
