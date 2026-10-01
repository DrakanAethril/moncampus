<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\ClassBoard;
use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\RandomDraw;
use App\Entity\User;
use App\Enum\ClassBoardWidgetType;
use App\Repository\GroupBatchRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\RandomDrawRepository;
use App\Security\StructureAccessChecker;

/**
 * What the board reads from the platform (design/validated/tableau-virtuel.md, §6): one method per
 * widget that reads something, each applying the access rules of the screen the data comes from.
 *
 * Every answer carries a `state`, and the partials draw the four that are not « ok » the same way:
 * - `no_class`: the board is linked to no class;
 * - `not_teaching`: it is, but its owner no longer teaches there - asked again at every opening,
 *   so a board outlives the timetable that made it without showing a class one has left;
 * - `source_missing`: the saved draw, lot… it points at is gone or no longer readable - never a
 *   500, the widget offers to choose another;
 * - `ok`.
 *
 * Read-only, every one of them. The board writes nothing the students see elsewhere; the one write
 * outside it - a saved draw's history - goes through the random draw tool's own routes.
 */
final class ClassBoardWidgetData
{
    public const string OK = 'ok';
    public const string NO_CLASS = 'no_class';
    public const string NOT_TEACHING = 'not_teaching';
    public const string SOURCE_MISSING = 'source_missing';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly RandomDrawRepository $randomDrawRepository,
        private readonly GroupBatchRepository $groupBatchRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
    ) {
    }

    /**
     * The view data of one stored widget.
     *
     * @param array<string, mixed> $widget
     *
     * @return array<string, mixed>
     */
    public function forWidget(ClassBoard $board, array $widget): array
    {
        $type = ClassBoardWidgetType::tryFrom(\is_string($widget['type'] ?? null) ? $widget['type'] : '');
        $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];

        if (null === $type) {
            return ['state' => self::SOURCE_MISSING];
        }

        if ($type->readsClass()) {
            $state = $this->classState($board);
            if (self::OK !== $state) {
                return ['state' => $state];
            }
        }

        return match ($type) {
            ClassBoardWidgetType::RandomDraw => $this->randomDraw($board, $config),
            ClassBoardWidgetType::Groups => $this->groups($board, $config),
            ClassBoardWidgetType::TeamCounter => $this->teamCounter($board, $config),
            default => ['state' => self::OK],
        };
    }

    /**
     * Whether the board's class may be read by its owner today.
     */
    public function classState(ClassBoard $board): string
    {
        $program = $board->getProgram();
        if (null === $program) {
            return self::NO_CLASS;
        }

        return $this->accessChecker->isProgramTeacher($program) ? self::OK : self::NOT_TEACHING;
    }

    /**
     * The random draw tool's own list, filter and replacement rule. Linked to a saved draw, the
     * draw's state wins over the widget's: the tool and the board show the same history.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function randomDraw(ClassBoard $board, array $config): array
    {
        $program = $this->program($board);
        $owner = $board->getOwner();
        $students = $this->roster($program);
        $rosterIds = array_column($students, 'id');

        $draws = $this->randomDrawRepository->findAllForTeacherAndProgram($owner, $program);
        $drawId = \is_int($config['drawId'] ?? null) ? $config['drawId'] : null;
        $draw = null;
        if (null !== $drawId) {
            $draw = $this->randomDrawRepository->findOneForTeacherAndProgram($drawId, $owner, $program);
            if (null === $draw) {
                return ['state' => self::SOURCE_MISSING, 'draws' => $this->drawChoices($draws)];
            }
        }

        $optionId = $draw?->getOption()?->getId() ?? (\is_int($config['optionId'] ?? null) ? $config['optionId'] : null);
        $options = array_values(array_map(
            static fn (Option $option): array => ['id' => (int) $option->getId(), 'label' => $option->getShortName()],
            $program->getOptions()->toArray(),
        ));
        if (null !== $optionId && !\in_array($optionId, array_column($options, 'id'), true)) {
            $optionId = null;
        }

        return [
            'state' => self::OK,
            'program' => $program,
            'students' => $students,
            'options' => $options,
            'draws' => $this->drawChoices($draws),
            'draw' => null === $draw ? null : [
                'id' => $draw->getId(),
                'name' => $draw->getName(),
                'drawnIds' => array_values(array_filter(
                    $draw->getDrawnStudentIds(),
                    static fn (int $id): bool => \in_array($id, $rosterIds, true),
                )),
            ],
            'optionId' => $optionId,
            'allowRepeat' => $draw?->isAllowRepeat() ?? (true === ($config['allowRepeat'] ?? false)),
        ];
    }

    /**
     * A saved lot of groups, the teacher's own or one a colleague shared - read, never changed.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function groups(ClassBoard $board, array $config): array
    {
        $program = $this->program($board);
        $lots = $this->readableLots($board->getOwner(), $program);
        $batchId = \is_int($config['batchId'] ?? null) ? $config['batchId'] : null;

        if (null === $batchId) {
            return ['state' => self::OK, 'lots' => $this->lotChoices($lots), 'lot' => null];
        }

        $lot = $lots[$batchId] ?? null;
        if (null === $lot) {
            return ['state' => self::SOURCE_MISSING, 'lots' => $this->lotChoices($lots)];
        }

        return ['state' => self::OK, 'lots' => $this->lotChoices($lots), 'lot' => $this->lotView($lot, $program)];
    }

    /**
     * The counter needs no class: its teams can be typed by hand. A lot of groups is only offered,
     * and only read, when the board's class may be read.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function teamCounter(ClassBoard $board, array $config): array
    {
        $teams = \is_array($config['teams'] ?? null) ? $config['teams'] : [];
        if (self::OK !== $this->classState($board)) {
            return ['state' => self::OK, 'lots' => [], 'lot' => null, 'teams' => $teams];
        }

        $program = $this->program($board);
        $lots = $this->readableLots($board->getOwner(), $program);
        $batchId = \is_int($config['batchId'] ?? null) ? $config['batchId'] : null;
        $lot = null !== $batchId && isset($lots[$batchId]) ? $this->lotView($lots[$batchId], $program) : null;

        return ['state' => self::OK, 'lots' => $this->lotChoices($lots), 'lot' => $lot, 'teams' => $teams];
    }

    private function program(ClassBoard $board): Program
    {
        $program = $board->getProgram();
        \assert(null !== $program);

        return $program;
    }

    /**
     * The class list as the random draw tool builds it: full name, short name, options.
     *
     * @return list<array{id: int, name: string, shortName: string, optionIds: list<int>}>
     */
    private function roster(Program $program): array
    {
        $optionsByStudentId = $this->studentOptionRepository->findOptionsByStudentForProgram($program);

        $students = array_map(
            static fn (User $student): array => [
                'id' => (int) $student->getId(),
                'name' => $student->getDisplayName() ?? $student->getUsername(),
                'shortName' => $student->getShortDisplayName() ?? $student->getUsername(),
                'optionIds' => array_map(
                    static fn (Option $option): int => (int) $option->getId(),
                    $optionsByStudentId[$student->getId()] ?? [],
                ),
            ],
            $program->getStudents()->toArray(),
        );
        usort($students, static fn (array $a, array $b): int => strcoll($a['name'], $b['name']));

        return $students;
    }

    /**
     * @param list<RandomDraw> $draws
     *
     * @return list<array{id: int, name: string}>
     */
    private function drawChoices(array $draws): array
    {
        return array_map(
            static fn (RandomDraw $draw): array => ['id' => (int) $draw->getId(), 'name' => $draw->getName()],
            $draws,
        );
    }

    /**
     * @return array<int, GroupBatch>
     */
    private function readableLots(User $owner, Program $program): array
    {
        $lots = [];
        foreach ([
            ...$this->groupBatchRepository->findAllForTeacherAndProgram($owner, $program),
            ...$this->groupBatchRepository->findAllSharedWithTeacherForProgram($owner, $program),
        ] as $lot) {
            $lots[(int) $lot->getId()] = $lot;
        }

        return $lots;
    }

    /**
     * @param array<int, GroupBatch> $lots
     *
     * @return list<array{id: int, name: string}>
     */
    private function lotChoices(array $lots): array
    {
        return array_values(array_map(
            static fn (GroupBatch $lot): array => ['id' => (int) $lot->getId(), 'name' => $lot->getName()],
            $lots,
        ));
    }

    /**
     * A lot hydrated against the current class list: a student who has left is dropped, as on the
     * group creation screen.
     *
     * @return array{id: int, name: string, groups: list<list<string>>}
     */
    private function lotView(GroupBatch $lot, Program $program): array
    {
        $names = [];
        foreach ($program->getStudents() as $student) {
            $names[(int) $student->getId()] = $student->getShortDisplayName() ?? $student->getUsername();
        }

        return [
            'id' => (int) $lot->getId(),
            'name' => $lot->getName(),
            'groups' => array_map(
                static fn (array $ids): array => array_values(array_filter(array_map(
                    static fn (int $id): ?string => $names[$id] ?? null,
                    $ids,
                ), static fn (?string $name): bool => null !== $name)),
                $lot->getGroups(),
            ),
        ];
    }
}
