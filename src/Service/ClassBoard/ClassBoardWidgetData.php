<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\Assignment;
use App\Entity\ClassBoard;
use App\Entity\FileLibraryNode;
use App\Entity\GroupBatch;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\RandomDraw;
use App\Entity\User;
use App\Enum\ClassBoardWidgetType;
use App\Enum\Feature;
use App\Repository\AssignmentRepository;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\GroupBatchRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\RandomDrawRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\FeatureAccess;
use App\Security\LessonLogEditors;
use App\Security\ProgramTimetableAccess;
use App\Security\StructureAccessChecker;
use App\Security\Voter\FileLibraryVoter;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\FileUploadService;
use App\Service\HtmlPlainText;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    private const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly RandomDrawRepository $randomDrawRepository,
        private readonly GroupBatchRepository $groupBatchRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly SeanceInstanceRepository $seanceInstanceRepository,
        private readonly SeanceTemplateRepository $seanceTemplateRepository,
        private readonly SequenceTemplateRepository $sequenceTemplateRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly FileLibraryNodeRepository $fileLibraryNodeRepository,
        private readonly LessonLogEditors $lessonLogEditors,
        private readonly ProgramTimetableAccess $timetableAccess,
        private readonly FeatureAccess $featureAccess,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly FileUploadService $fileUploadService,
        private readonly HtmlPlainText $plainText,
        private readonly VideoEmbed $videoEmbed,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
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
            ClassBoardWidgetType::Session => $this->session($board),
            ClassBoardWidgetType::SessionPlan => $this->sessionPlan($board, $config),
            ClassBoardWidgetType::Work => $this->work($board, $config),
            ClassBoardWidgetType::Media => $this->media($board, $config),
            ClassBoardWidgetType::Video => $this->video($board, $config),
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

    /**
     * Today's slots of the class whose teacher - or co-animator - is the board's owner, the rule of
     * the cahier de texte (LessonLogEditors), and only when the class's timetable may be read
     * (ProgramTimetableAccess: the feature, the formation's setting, its visibility).
     *
     * @return list<LessonSession>
     */
    public function todaySlots(ClassBoard $board): array
    {
        $program = $board->getProgram();
        if (null === $program || !$this->timetableAccess->isVisible($program)) {
            return [];
        }

        $today = $this->clock->now()->setTime(0, 0);
        $owner = $board->getOwner();

        return array_values(array_filter(
            $this->timetableAccess->filterSessions($this->lessonSessionRepository->findForProgramBetween($program, $today, $today)),
            fn (LessonSession $slot): bool => $this->lessonLogEditors->mayEdit($slot, $owner),
        ));
    }

    /**
     * « Séance du jour »: the slot under way, else the next one; every other slot of the day can
     * be shown instead. Of the library séance placed on it, its title, its sequence and its
     * objectives - nothing else of the sheet (§1.10).
     *
     * @return array<string, mixed>
     */
    private function session(ClassBoard $board): array
    {
        $slots = $this->todaySlots($board);
        $current = TodaySlots::current($slots, $this->clock->now());

        $views = [];
        foreach ($slots as $slot) {
            $seance = $this->seanceInstanceRepository->findOneByLessonSession($slot);
            $start = TodaySlots::start($slot);
            $end = TodaySlots::end($slot);
            $topic = $slot->getTopic()?->getName() ?? $slot->getDisplayName();
            $views[] = [
                'key' => (string) $slot->getId(),
                'label' => \sprintf('%s – %s · %s', $start?->format('H:i'), $end?->format('H:i'), $seance?->getTitre() ?? $topic),
                'when' => \sprintf('%s · %s – %s', $this->dayLabel($start), $start?->format('H:i'), $end?->format('H:i')),
                'room' => $slot->getClassRoom()?->getName(),
                'topic' => $topic,
                'title' => $seance?->getTitre(),
                'sequence' => $seance?->getSequenceInstance()?->getTitre(),
                'objectives' => $this->lines($seance?->getObjectifs()),
            ];
        }

        return ['state' => self::OK, 'slots' => $views, 'current' => null === $current ? null : (string) $current->getId()];
    }

    /**
     * « Déroulé de séance »: the phases, NAME AND DURATION ONLY - never `enseignant`,
     * `difficultes`, `moyensSupports` or `contenu`. From the séance placed on today's slot, from a
     * séance of the library the owner may read, or typed by hand into the widget.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function sessionPlan(ClassBoard $board, array $config): array
    {
        $source = \is_string($config['source'] ?? null) ? $config['source'] : 'session';
        $view = ['state' => self::OK, 'manual' => false, 'phases' => [], 'seances' => [], 'sourceLabel' => '', 'emptyMessage' => ''];

        if ('manual' === $source) {
            return [
                'manual' => true,
                'sourceLabel' => $this->translator->trans('classBoardPlanManualLabel'),
                'emptyMessage' => $this->translator->trans('classBoardPlanManualEmptyMessage'),
            ] + $view;
        }

        if ('library' === $source) {
            $view['seances'] = $this->librarySeances($board->getOwner());
            $view['emptyMessage'] = $this->translator->trans('classBoardPlanChooseSeanceMessage');
            $seanceId = \is_int($config['seanceId'] ?? null) ? $config['seanceId'] : null;
            if (null === $seanceId) {
                return $view;
            }
            $seance = $this->seanceTemplateRepository->find($seanceId);
            $sequence = $seance?->getSequenceTemplate();
            if (null === $seance || null === $sequence || !$this->authorizationChecker->isGranted(SequenceTemplateVoter::VIEW, $sequence)) {
                return ['state' => self::SOURCE_MISSING] + $view;
            }
            $phases = [];
            foreach ($seance->getSeancePhaseTemplates() as $phase) {
                $phases[] = ['name' => (string) $phase->getNom(), 'minutes' => TodaySlots::phaseMinutes($phase->getDuree())];
            }

            return [
                'phases' => $phases,
                'sourceLabel' => $this->translator->trans('classBoardPlanLibraryLabel', ['%seance%' => (string) $seance->getTitre()]),
            ] + $view;
        }

        $slot = TodaySlots::current($this->todaySlots($board), $this->clock->now());
        $seance = null === $slot ? null : $this->seanceInstanceRepository->findOneByLessonSession($slot);
        if (null === $slot || null === $seance) {
            return [
                'emptyMessage' => $this->translator->trans(null === $slot ? 'classBoardSessionNoSlotMessage' : 'classBoardPlanNoSeanceMessage'),
            ] + $view;
        }

        $phases = [];
        foreach ($seance->getSeancePhaseInstances() as $phase) {
            $phases[] = ['name' => (string) $phase->getNom(), 'minutes' => TodaySlots::phaseMinutes($phase->getDuree())];
        }

        return [
            'phases' => $phases,
            'sourceLabel' => $this->translator->trans('classBoardPlanSessionLabel', [
                '%seance%' => (string) $seance->getTitre(),
                '%planned%' => array_sum(array_column($phases, 'minutes')),
                '%slot%' => TodaySlots::minutes($slot),
            ]),
            'emptyMessage' => $this->translator->trans('classBoardPlanNoPhaseMessage'),
        ] + $view;
    }

    /**
     * « Travail à faire »: only what the board's owner gave this class themself, already visible to
     * the students and still to come - one line per work, at its next deadline. Never a state per
     * student.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function work(ClassBoard $board, array $config): array
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);
        $rows = \is_int($config['rows'] ?? null) ? $config['rows'] : 5;

        $items = [];
        foreach ($this->assignmentRepository->findForPrograms([$this->program($board)], $board->getOwner()) as $assignment) {
            if ($assignment->getCreatedBy() !== $board->getOwner() || $assignment->isDeleted() || !$assignment->isVisibleFor($now)) {
                continue;
            }
            $due = $this->nextDueDate($assignment, $today);
            if (null === $due) {
                continue;
            }
            $items[] = [
                'title' => (string) $assignment->getTitle(),
                'nature' => $this->translator->trans($assignment->getNature()->labelKey()),
                'due' => $due,
            ];
        }
        usort($items, static fn (array $a, array $b): int => $a['due'] <=> $b['due']);

        return ['state' => self::OK, 'items' => array_map(
            fn (array $item): array => $item + ['dueLabel' => $this->formatDate($item['due'], 'EEE d MMM')],
            \array_slice($items, 0, $rows),
        )];
    }

    /**
     * « Image / PDF »: a file of the owner's library, its address signed at this opening.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function media(ClassBoard $board, array $config): array
    {
        $files = $this->libraryFiles($board->getOwner(), [...self::IMAGE_EXTENSIONS, 'pdf']);
        $fileId = \is_int($config['fileId'] ?? null) ? $config['fileId'] : null;
        if (null === $fileId) {
            return ['state' => self::OK, 'files' => $files, 'file' => null];
        }

        $node = $this->readableFile($fileId);
        $key = $node?->getStorageKey();
        if (null === $node || null === $key || !\in_array($node->getExtension(), [...self::IMAGE_EXTENSIONS, 'pdf'], true)) {
            return ['state' => self::SOURCE_MISSING, 'files' => $files];
        }

        return [
            'state' => self::OK,
            'files' => $files,
            'file' => [
                'name' => $node->getName(),
                'kind' => 'pdf' === $node->getExtension() ? 'pdf' : 'image',
                'url' => $this->fileUploadService->downloadUrl($key, $node->getName(), FileUploadService::PLAYBACK_LIFETIME),
            ],
        ];
    }

    /**
     * « Vidéo »: an address of the teacher's choice - no list of domains (§1.6) - or a video of their
     * library, played inline like « Visionner ».
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function video(ClassBoard $board, array $config): array
    {
        $files = $this->featureAccess->isEnabled(Feature::FileLibrary, $board->getOwner())
            ? $this->libraryFiles($board->getOwner(), self::VIDEO_EXTENSIONS)
            : [];

        $fileId = \is_int($config['fileId'] ?? null) ? $config['fileId'] : null;
        if (null !== $fileId && [] !== $files) {
            $node = $this->readableFile($fileId);
            $key = $node?->getStorageKey();
            if (null === $node || null === $key || !$node->isVideo()) {
                return ['state' => self::SOURCE_MISSING, 'files' => $files];
            }

            return [
                'state' => self::OK,
                'files' => $files,
                'embed' => ['kind' => VideoEmbed::FILE, 'src' => $this->fileUploadService->playbackUrl($key, $node->getName()), 'url' => null],
            ];
        }

        $url = \is_string($config['url'] ?? null) ? $config['url'] : '';

        return ['state' => self::OK, 'files' => $files, 'embed' => '' === $url ? null : $this->videoEmbed->resolve($url)];
    }

    private function nextDueDate(Assignment $assignment, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $dates = [$assignment->getDueDate()];
        foreach ($assignment->getExpectedProductions() as $production) {
            $dates[] = $production->getEffectiveDueDate();
        }

        $next = null;
        foreach ($dates as $date) {
            if (null !== $date && $date >= $today && (null === $next || $date < $next)) {
                $next = $date;
            }
        }

        return $next;
    }

    /**
     * The séances of the owner's library, labelled « Séquence › Séance ».
     *
     * @return list<array{id: int, label: string}>
     */
    private function librarySeances(User $owner): array
    {
        $choices = [];
        foreach ($this->sequenceTemplateRepository->findForTeacher($owner) as $sequence) {
            foreach ($sequence->getSeanceTemplates() as $seance) {
                $choices[] = ['id' => (int) $seance->getId(), 'label' => \sprintf('%s › %s', $sequence->getTitre(), $seance->getTitre())];
            }
        }

        return $choices;
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<array{id: int, label: string}>
     */
    private function libraryFiles(User $owner, array $extensions): array
    {
        if (!$this->featureAccess->isEnabled(Feature::FileLibrary, $owner)) {
            return [];
        }

        return array_map(
            static fn (FileLibraryNode $node): array => ['id' => (int) $node->getId(), 'label' => $node->getName()],
            $this->fileLibraryNodeRepository->findFilesWithExtensions($owner, $extensions),
        );
    }

    private function readableFile(int $id): ?FileLibraryNode
    {
        $node = $this->fileLibraryNodeRepository->find($id);

        return null !== $node && $node->isFile() && !$node->isDeleted() && $this->authorizationChecker->isGranted(FileLibraryVoter::VIEW, $node)
            ? $node
            : null;
    }

    /**
     * Objectives are free text that may hold HTML: one line per objective, list markers dropped.
     *
     * @return list<string>
     */
    private function lines(?string $text): array
    {
        $plain = $this->plainText->linesFromHtml($text) ?? '';
        $lines = [];
        foreach (preg_split('/\R/u', $plain) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•–]|\d+[.)])\s*/u', '', $line));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function formatDate(\DateTimeImmutable $date, string $pattern): string
    {
        return (string) (new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern))->format($date);
    }

    private function dayLabel(?\DateTimeImmutable $day): string
    {
        if (null === $day) {
            return '';
        }
        $label = $this->formatDate($day, 'EEEE d MMMM');
        if (str_starts_with($this->translator->getLocale(), 'fr') && 1 === (int) $day->format('j')) {
            $label = (string) preg_replace('/ 1 /u', ' 1er ', $label, 1);
        }

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
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
