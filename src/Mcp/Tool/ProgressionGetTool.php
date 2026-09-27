<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\LessonSession;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\Feature;
use App\Mcp\McpLinks;
use App\Mcp\McpTimetable;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgressionRepository;
use App\Repository\ProgressionSeancePlacementRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\TopicRepository;
use App\Security\Voter\ProgressionVoter;
use App\Service\ProgressionSequenceAvailability;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The teacher's progressions pédagogiques, and the emploi du temps they are laid onto - read only.
 *
 * Without a matière: every matière the teacher holds this school year (or is named on as a
 * co-animator), with its timetable volume, what is left of it, and its progression if there is one.
 * With one: the whole year of that matière's créneaux, each with the séance planned on it, the
 * progression's séquences and séances in order, and the class's séquences not planned yet - what
 * Claude needs to follow where the class is at, or to **suggest** a progression.
 *
 * Suggest, and nothing more: no tool of the connector writes a progression. Planning is the
 * teacher's gesture on the progression screens, where the placement is computed and validated.
 *
 * A matière is reached the way App\Controller\ProgressionController reaches it: held by the
 * teacher (Topic::$teachers), or carrying a progression App\Security\Voter\ProgressionVoter lets
 * them edit - which is how a co-animator gets in.
 */
final readonly class ProgressionGetTool implements McpTool
{
    public function __construct(
        private SchoolYearRepository $schoolYears,
        private TopicRepository $topics,
        private ProgressionRepository $progressions,
        private LessonSessionRepository $sessions,
        private ProgressionSeancePlacementRepository $placements,
        private ProgressionSequenceAvailability $availability,
        private AuthorizationCheckerInterface $authorization,
        private McpTimetable $timetable,
        private McpLinks $links,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'progression_get';
    }

    public function title(): string
    {
        return 'Mes progressions pédagogiques';
    }

    public function description(): string
    {
        return 'Sans topicId : liste les matières de l\'enseignant pour l\'année scolaire en cours, avec le volume horaire prévu, le volume de l\'emploi du temps (fait et restant) et la progression si elle existe. Avec topicId : tous les créneaux de l\'année de cette matière (date, horaires, durée, groupes, séance prévue dessus), la progression (séquences et séances dans l\'ordre, durées prévues et placées, statut de placement, évaluations) et les séquences de la classe pas encore planifiées. Sert à savoir où en est la classe, et à suggérer une progression d\'après l\'emploi du temps. Lecture seule : ce connecteur n\'écrit aucune progression.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topicId' => ['type' => 'integer', 'description' => 'La matière à détailler (voir la liste sans argument, ou timetable_get).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::Progression];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $topics = $this->reachableTopics($call->user);
        $topicId = $call->optionalId('topicId');

        if (null === $topicId) {
            return $this->overview($topics);
        }

        foreach ($topics as $topic) {
            if ($topic->getId() === $topicId) {
                return $this->detail($topic, $call->user);
            }
        }

        throw new McpToolException(\sprintf('Matière %d introuvable parmi celles dont vous êtes titulaire cette année.', $topicId));
    }

    /**
     * @param list<Topic> $topics
     */
    private function overview(array $topics): McpToolResult
    {
        $slotsByTopic = [];
        foreach ($this->sessions->findOrderedForTopics($topics) as $session) {
            $slotsByTopic[(int) $session->getTopic()?->getId()][] = $session;
        }

        $rows = [];
        foreach ($topics as $topic) {
            $progression = $this->progressions->findOneForTopic($topic);
            $rows[] = [
                'topicId' => $topic->getId(),
                'name' => $topic->getName(),
                'program' => ['id' => $topic->getProgram()?->getId(), 'shortName' => $topic->getProgram()?->getDisplayShortName()],
                'targetHours' => (float) $topic->getTotalTargetHours(),
                'timetable' => $this->volume($slotsByTopic[(int) $topic->getId()] ?? []),
                'progression' => null === $progression ? null : [
                    'id' => $progression->getId(),
                    'owner' => $progression->getTeacher()?->getDisplayName(),
                    'coAnimated' => $progression->isCoAnimated(),
                    'sequences' => $progression->getSequences()->count(),
                    'plannedMinutes' => $progression->getPlannedMinutes(),
                    'placedMinutes' => $progression->getPlacedMinutes(),
                    'url' => $this->links->url('app_progression_show', ['id' => $progression->getId()]),
                ],
            ];
        }

        return McpToolResult::data(
            \sprintf('%d matières cette année, dont %d avec une progression.', \count($rows), \count(array_filter($rows, static fn (array $row): bool => null !== $row['progression']))),
            ['topics' => $rows],
        );
    }

    private function detail(Topic $topic, User $teacher): McpToolResult
    {
        $program = $topic->getProgram();
        $progression = $this->progressions->findOneForTopic($topic);
        $slots = $this->sessions->findOrderedForTopic($topic);
        $placements = $this->placements->findForLessonSessions($slots);

        $slotRows = [];
        foreach ($slots as $session) {
            $slotRows[] = [
                'sessionId' => $session->getId(),
                'date' => $session->getDay()?->format('Y-m-d'),
                'start' => $session->getStartHour()?->format('H:i'),
                'end' => $session->getEndHour()?->format('H:i'),
                'minutes' => McpTimetable::minutes($session),
                'groups' => $this->timetable->slot($session)['groups'],
                'mine' => $session->getTeacher() === $teacher,
                'plannedSeances' => $this->timetable->plannedSeances($placements[(int) $session->getId()] ?? []),
            ];
        }

        $unplanned = null !== $progression
            ? $this->availability->forProgression($progression)
            : (null === $program ? [] : $this->availability->forTeacher($program, $teacher));

        return McpToolResult::data(
            \sprintf(
                '%s en %s : %d créneaux dans l\'année, %s.',
                $topic->getName(),
                $program?->getDisplayShortName(),
                \count($slots),
                null === $progression ? 'pas encore de progression' : \sprintf('progression de %d séquences', $progression->getSequences()->count()),
            ),
            [
                'topic' => [
                    'id' => $topic->getId(),
                    'name' => $topic->getName(),
                    'program' => ['id' => $program?->getId(), 'shortName' => $program?->getDisplayShortName(), 'name' => $program?->getDisplayName()],
                    'targetHours' => ['cm' => (float) $topic->getTargetCmHours(), 'td' => (float) $topic->getTargetTdHours(), 'tp' => (float) $topic->getTargetTpHours(), 'total' => (float) $topic->getTotalTargetHours()],
                ],
                'timetable' => $this->volume($slots),
                'slots' => $slotRows,
                'progression' => null === $progression ? null : $this->progression($progression),
                'unplannedSequences' => array_map($this->sequenceInstance(...), $unplanned),
                'url' => null === $progression ? $this->links->url('app_progression_new') : $this->links->url('app_progression_show', ['id' => $progression->getId()]),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function progression(Progression $progression): array
    {
        $sequences = [];
        foreach ($progression->getSequences() as $sequence) {
            $sequences[] = $this->sequence($sequence);
        }

        $teachers = [];
        foreach ($progression->getTeachers() as $teacher) {
            $teachers[] = $teacher->getDisplayName();
        }

        return [
            'id' => $progression->getId(),
            'teachers' => $teachers,
            'plannedMinutes' => $progression->getPlannedMinutes(),
            'placedMinutes' => $progression->getPlacedMinutes(),
            'evaluationsByNature' => $progression->getEvaluationCountsByNature(),
            'sequences' => $sequences,
        ];
    }

    /** @return array<string, mixed> */
    private function sequence(ProgressionSequence $sequence): array
    {
        $seances = [];
        foreach ($sequence->getSeances() as $seance) {
            if (!$seance->isRemoved()) {
                $seances[] = $this->seance($seance);
            }
        }

        return [
            'id' => $sequence->getId(),
            'position' => $sequence->getPosition(),
            'title' => $sequence->getTitle(),
            'sequenceInstanceId' => $sequence->getSequenceInstance()?->getId(),
            'status' => $sequence->getStatus()->value,
            'placeInTimetable' => $sequence->isPlaceInTimetable(),
            'forcedStartDate' => $sequence->getForcedStartDate()?->format('Y-m-d'),
            'oneSeancePerWeek' => $sequence->isOneSeancePerWeek(),
            'plannedMinutes' => $sequence->getPlannedMinutes(),
            'placedMinutes' => $sequence->getPlacedMinutes(),
            'firstDay' => $sequence->getFirstPlacedDay()?->format('Y-m-d'),
            'lastDay' => $sequence->getLastPlacedDay()?->format('Y-m-d'),
            'objectives' => $this->timetable->plain($sequence->getSequenceInstance()?->getObjectifs()),
            'seances' => $seances,
        ];
    }

    /** @return array<string, mixed> */
    private function seance(ProgressionSeance $seance): array
    {
        $placed = [];
        foreach ($seance->getActivePlacements() as $placement) {
            $placed[] = [
                'sessionId' => $placement->getLessonSession()?->getId(),
                'date' => $placement->getLessonSession()?->getDay()?->format('Y-m-d'),
                'minutes' => $placement->getDurationMinutes(),
                'confirmed' => $placement->isConfirmed(),
            ];
        }

        return [
            'id' => $seance->getId(),
            'title' => $seance->getTitle(),
            'seanceInstanceId' => $seance->getSeanceInstance()?->getId(),
            'plannedMinutes' => $seance->getPlannedMinutesOrZero(),
            'status' => $seance->getStatus()->value,
            'evaluation' => $seance->getEvaluationNature()?->value,
            'perGroup' => $seance->isPerGroup(),
            'objectives' => $this->timetable->plain($seance->getSeanceInstance()?->getObjectifs()),
            'placements' => $placed,
        ];
    }

    /** @return array<string, mixed> */
    private function sequenceInstance(SequenceInstance $instance): array
    {
        $minutes = 0;
        foreach ($instance->getSeanceInstances() as $seance) {
            $minutes += (int) $seance->getDuree();
        }

        return [
            'sequenceInstanceId' => $instance->getId(),
            'title' => $instance->getTitre(),
            'seances' => $instance->getSeanceInstances()->count(),
            'minutes' => $minutes,
            'objectives' => $this->timetable->plain($instance->getObjectifs()),
        ];
    }

    /**
     * A matière's timetable volume: the whole year, and what is still to come from today.
     *
     * @param list<LessonSession> $slots
     *
     * @return array{slots: int, minutes: int, remainingSlots: int, remainingMinutes: int, firstDay: ?string, lastDay: ?string}
     */
    private function volume(array $slots): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $minutes = 0;
        $remainingSlots = 0;
        $remainingMinutes = 0;

        foreach ($slots as $session) {
            $length = McpTimetable::minutes($session);
            $minutes += $length;
            if (null !== $session->getDay() && $session->getDay() >= $today) {
                ++$remainingSlots;
                $remainingMinutes += $length;
            }
        }

        $first = $slots[0] ?? null;
        $last = [] === $slots ? null : $slots[\count($slots) - 1];

        return [
            'slots' => \count($slots),
            'minutes' => $minutes,
            'remainingSlots' => $remainingSlots,
            'remainingMinutes' => $remainingMinutes,
            'firstDay' => $first?->getDay()?->format('Y-m-d'),
            'lastDay' => $last?->getDay()?->format('Y-m-d'),
        ];
    }

    /**
     * The matières this teacher may plan this school year: the ones they hold, plus the ones whose
     * progression names them - in the order the progression list shows them.
     *
     * @return list<Topic>
     */
    private function reachableTopics(User $teacher): array
    {
        $schoolYear = $this->schoolYears->findCurrentOrMostRecent();
        if (null === $schoolYear) {
            return [];
        }

        $topics = [];
        foreach ($this->topics->findForTeacherInSchoolYear($teacher, $schoolYear) as $topic) {
            $topics[(int) $topic->getId()] = $topic;
        }
        foreach ($this->progressions->findForTeacher($teacher, $schoolYear) as $progression) {
            $topic = $progression->getTopic();
            if (null !== $topic && $this->authorization->isGranted(ProgressionVoter::EDIT, $progression)) {
                $topics[(int) $topic->getId()] ??= $topic;
            }
        }

        return array_values($topics);
    }
}
