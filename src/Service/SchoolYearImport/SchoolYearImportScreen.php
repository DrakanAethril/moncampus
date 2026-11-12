<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Topic;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\Copier\EvaluationCalendarCopier;
use App\Service\SchoolYearImport\Copier\PeriodCalendarCopier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the import's screens show, built from the planner's reports - the screens compute nothing of
 * their own (design/validated/school-year-import.md §2).
 *
 * Screen 2's counts come from one dry run with every box and every formation ticked: each formation
 * carries, per block, what it would receive, and the summary panel only adds up the ticked ones.
 *
 * @phpstan-import-type ImportReport from SchoolYearImportReport
 * @phpstan-import-type ImportProgramEntry from SchoolYearImportReport
 *
 * @phpstan-type ChooseProgram array{id: int, name: string, cohort: string, options: string, modalities: string, teachers: int, topics: int, test: bool, status: string, counts: array<string, int>, dates: int}
 * @phpstan-type ChooseView array{weeks: int, programs: list<ChooseProgram>, calendars: array<string, list<array{name: string, status: string, periods: int}>>}
 * @phpstan-type AddedLine array{key: string, count: int}
 */
final readonly class SchoolYearImportScreen
{
    /**
     * Which lines of a report belong to which box - what screen 2 adds up when the box is ticked.
     * The blocks absent (holders) add nothing of their own.
     */
    public const array BLOCK_KEYS = [
        'team' => ['teachers'],
        'referents' => ['referents', 'referentOptions'],
        'teacher_options' => ['teacherOptions'],
        'topics' => ['topics'],
        'syllabus' => ['syllabus'],
        'skills' => ['skillGroups'],
        'calendar_links' => ['periodLinks', 'evaluationCalendar'],
        'financial' => ['financialItems', 'lessonTypeCosts'],
        'internship' => ['certifications', 'internshipInfo', 'legalNames', 'examModalities', 'contractModalities', 'internshipPeriods', 'ecfSettings'],
        'portfolio' => ['portfolio'],
        'portfolio_validators' => ['portfolioValidators'],
        'game' => ['gameSettings', 'gameRules', 'rewards'],
        'crm' => ['crm'],
    ];

    /** The lines a formation's « ce qu'elle reçoit » lists, in this order. */
    private const array SHOWN_KEYS = [
        'options', 'modalities', 'teachers', 'referents', 'teacherOptions', 'topicGroups', 'topics', 'syllabus',
        'skillGroups', 'skills', 'skillLevels', 'periodLinks', 'evaluationCalendar', 'financialItems', 'lessonTypeCosts',
        'certifications', 'internshipInfo', 'legalNames', 'examModalities', 'contractModalities', 'internshipPeriods',
        'ecfSettings', 'portfolio', 'portfolioValidators', 'gameSettings', 'gameRules', 'rewards', 'crm',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SchoolYearImportPlanner $planner,
    ) {
    }

    /**
     * The other active years an import can come from (Q5: more recent ones too), the latest first,
     * with their number of active formations.
     *
     * @return list<array{year: SchoolYear, label: string, programs: int}>
     */
    public function sourceYears(SchoolYear $target): array
    {
        /** @var list<SchoolYear> $years */
        $years = $this->entityManager->getRepository(SchoolYear::class)->findBy(['inactiveDate' => null], ['startDate' => 'DESC']);
        $counts = $this->programCounts($years);

        $rows = [];
        foreach ($years as $year) {
            if ($year !== $target) {
                $rows[] = ['year' => $year, 'label' => self::label($year), 'programs' => $counts[(int) $year->getId()] ?? 0];
            }
        }

        return $rows;
    }

    /**
     * The most recent year that starts before the target one; failing that, the nearest.
     *
     * @param list<array{year: SchoolYear, label: string, programs: int}> $sources
     */
    public function defaultSource(SchoolYear $target, array $sources): ?SchoolYear
    {
        $start = $target->getStartDate();
        $best = null;
        $bestGap = null;
        foreach ($sources as ['year' => $year]) {
            if ($year->getStartDate() < $start) {
                return $year;
            }
            $gap = abs((int) $year->getStartDate()?->getTimestamp() - (int) $start?->getTimestamp());
            if (null === $bestGap || $gap < $bestGap) {
                [$best, $bestGap] = [$year, $gap];
            }
        }

        return $best;
    }

    /**
     * Active formations per year, in one query.
     *
     * @param list<SchoolYear> $years
     *
     * @return array<int, int>
     */
    public function programCounts(array $years): array
    {
        if ([] === $years) {
            return [];
        }

        /** @var list<array{year: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.schoolYear) AS year', 'COUNT(p.id) AS total')
            ->from(Program::class, 'p')
            ->where('p.schoolYear IN (:years)')
            ->andWhere('p.inactiveDate IS NULL')
            ->groupBy('p.schoolYear')
            ->setParameter('years', $years)
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['year']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return ChooseView */
    public function chooseView(SchoolYear $source, SchoolYear $target): array
    {
        $programs = $this->planner->activePrograms($source);
        $everything = new SchoolYearImportChoices(
            (int) $source->getId(),
            SchoolYearImportBlock::cases(),
            array_map(static fn (Program $program): int => (int) $program->getId(), $programs),
        );
        $report = $this->planner->plan($source, $target, $everything)->toArray();

        $entries = [];
        foreach ($report['programs'] as $entry) {
            $entries[$entry['sourceId']] = $entry;
        }
        $topics = $this->topicCounts($programs);

        $rows = [];
        foreach ($programs as $program) {
            $entry = $entries[(int) $program->getId()] ?? null;
            $counts = [];
            foreach (self::BLOCK_KEYS as $block => $keys) {
                $counts[$block] = 0;
                foreach ($keys as $key) {
                    $counts[$block] += $entry['added'][$key] ?? 0;
                }
            }
            $rows[] = [
                'id' => (int) $program->getId(),
                'name' => $program->getName(),
                'cohort' => (string) $program->getCohort()?->getName(),
                'options' => implode(', ', array_map(static fn ($option): string => $option->getShortName(), $program->getOptions()->toArray())),
                'modalities' => implode(', ', array_map(static fn ($modality): string => $modality->getName(), $program->getModalities()->toArray())),
                'teachers' => $program->getTeachers()->count(),
                'topics' => $topics[(int) $program->getId()] ?? 0,
                'test' => $program->isTestProgram(),
                'status' => $entry['status'] ?? SchoolYearImportReport::CREATED,
                'counts' => $counts,
                'dates' => $entry['shiftedDates'] ?? 0,
            ];
        }

        $calendars = [PeriodCalendarCopier::KIND => [], EvaluationCalendarCopier::KIND => []];
        foreach ($report['calendars'] as $calendar) {
            $calendars[$calendar['kind']][] = ['name' => $calendar['name'], 'status' => $calendar['status'], 'periods' => $calendar['periods']];
        }

        return ['weeks' => $report['weeks'], 'programs' => $rows, 'calendars' => $calendars];
    }

    /**
     * The summary of a report: formations created and completed, calendars created, and the total
     * of every line - for screens 3 and 4.
     *
     * @param ImportReport $report
     *
     * @return array{created: int, completed: int, periodCalendars: int, evaluationCalendars: int, dates: int, withoutHolder: int, added: array<string, int>}
     */
    public static function totals(array $report): array
    {
        $totals = ['created' => 0, 'completed' => 0, 'periodCalendars' => 0, 'evaluationCalendars' => 0, 'dates' => \count($report['shiftedDates']), 'withoutHolder' => 0, 'added' => []];
        foreach ($report['programs'] as $entry) {
            ++$totals[SchoolYearImportReport::CREATED === $entry['status'] ? 'created' : 'completed'];
            $totals['withoutHolder'] += $entry['withoutHolder'];
            foreach ($entry['added'] as $key => $count) {
                $totals['added'][$key] = ($totals['added'][$key] ?? 0) + $count;
            }
        }
        foreach ($report['calendars'] as $calendar) {
            if (SchoolYearImportReport::CREATED === $calendar['status']) {
                ++$totals[PeriodCalendarCopier::KIND === $calendar['kind'] ? 'periodCalendars' : 'evaluationCalendars'];
            }
        }

        return $totals;
    }

    /**
     * What a formation receives, as translation keys and counts, in a fixed order.
     *
     * @param ImportProgramEntry $entry
     *
     * @return list<AddedLine>
     */
    public static function addedLines(array $entry): array
    {
        $lines = [];
        foreach (self::SHOWN_KEYS as $key) {
            $count = $entry['added'][$key] ?? 0;
            if ($count > 0) {
                $lines[] = ['key' => 'schoolYearImportAdded'.ucfirst($key), 'count' => $count];
            }
        }

        return $lines;
    }

    public static function label(SchoolYear $year): string
    {
        return $year->getStartDate()?->format('Y').'-'.$year->getEndDate()?->format('Y');
    }

    /**
     * @param list<Program> $programs
     *
     * @return array<int, int>
     */
    private function topicCounts(array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        /** @var list<array{program: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(t.program) AS program', 'COUNT(t.id) AS total')
            ->from(Topic::class, 't')
            ->where('t.program IN (:programs)')
            ->andWhere('t.inactiveDate IS NULL')
            ->groupBy('t.program')
            ->setParameter('programs', $programs)
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['program']] = (int) $row['total'];
        }

        return $counts;
    }
}
