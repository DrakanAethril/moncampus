<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\User;

/**
 * What an import wrote, or would write: one entry per formation (created or completed, what it
 * received, what was left out and why), the calendars, the teachers not taken over, every date
 * moved. The planner's dry run fills it for screen 3; the importer's run fills it again under the
 * lock and stores it in the journal for screen 4 (R11).
 *
 * Notes are translation keys with their parameters, never sentences: the journal outlives a
 * rewording.
 *
 * @phpstan-type ImportNote array{key: string, params: array<string, string|int>}
 * @phpstan-type ImportProgramEntry array{sourceId: int, targetId: int|null, name: string, status: string, ambiguous: bool, added: array<string, int>, notes: list<ImportNote>, shiftedDates: int, withoutHolder: int}
 * @phpstan-type ImportCalendarEntry array{kind: string, name: string, status: string, periods: int}
 * @phpstan-type ImportShiftedDate array{where: string, from: string, to: string, flag: string|null}
 * @phpstan-type ImportReport array{source: string, target: string, weeks: int, calendars: list<ImportCalendarEntry>, programs: list<ImportProgramEntry>, skippedTeachers: list<array{name: string, reason: string}>, shiftedDates: list<ImportShiftedDate>}
 */
final class SchoolYearImportReport
{
    public const string CREATED = 'created';
    public const string COMPLETED = 'completed';
    public const string EXISTING = 'existing';

    public const string FLAG_CLAMPED = 'clamped';
    public const string FLAG_OUTSIDE = 'outside';

    /** @var array<int, ImportProgramEntry> */
    private array $programs = [];

    /** @var list<ImportCalendarEntry> */
    private array $calendars = [];

    /** @var array<int, array{name: string, reason: string}> */
    private array $skippedTeachers = [];

    /** @var list<ImportShiftedDate> */
    private array $shiftedDates = [];

    public function __construct(
        private readonly string $sourceLabel,
        private readonly string $targetLabel,
        private readonly int $weeks,
    ) {
    }

    public function program(ProgramPair $pair): void
    {
        $this->programs[$pair->key()] = [
            'sourceId' => (int) $pair->source->getId(),
            'targetId' => $pair->target?->getId(),
            'name' => $pair->name,
            'status' => $pair->created ? self::CREATED : self::COMPLETED,
            'ambiguous' => $pair->ambiguous,
            'added' => [],
            'notes' => [],
            'shiftedDates' => 0,
            'withoutHolder' => 0,
        ];
    }

    /** The target id, known once the importer has flushed the formation it created. */
    public function settle(ProgramPair $pair): void
    {
        if (isset($this->programs[$pair->key()])) {
            $this->programs[$pair->key()]['targetId'] = $pair->target?->getId();
        }
    }

    public function add(ProgramPair $pair, string $what, int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }
        $added = $this->programs[$pair->key()]['added'] ?? [];
        $added[$what] = ($added[$what] ?? 0) + $count;
        $this->programs[$pair->key()]['added'] = $added;
    }

    /** @param array<string, string|int> $params */
    public function note(ProgramPair $pair, string $key, array $params = []): void
    {
        $this->programs[$pair->key()]['notes'][] = ['key' => $key, 'params' => $params];
    }

    public function withoutHolder(ProgramPair $pair): void
    {
        ++$this->programs[$pair->key()]['withoutHolder'];
    }

    public function calendar(string $kind, string $name, string $status, int $periods): void
    {
        $this->calendars[] = ['kind' => $kind, 'name' => $name, 'status' => $status, 'periods' => $periods];
    }

    public function skippedTeacher(User $teacher, string $reason): void
    {
        $this->skippedTeachers[(int) $teacher->getId()] = ['name' => $teacher->getDisplayName() ?? $teacher->getUsername(), 'reason' => $reason];
    }

    public function shiftedDate(?ProgramPair $pair, string $where, \DateTimeImmutable $fromStart, ?\DateTimeImmutable $fromEnd, \DateTimeImmutable $toStart, ?\DateTimeImmutable $toEnd, ?string $flag): void
    {
        $this->shiftedDates[] = [
            'where' => $where,
            'from' => self::range($fromStart, $fromEnd),
            'to' => self::range($toStart, $toEnd),
            'flag' => $flag,
        ];
        if (null !== $pair) {
            ++$this->programs[$pair->key()]['shiftedDates'];
        }
    }

    /** @return ImportReport */
    public function toArray(): array
    {
        $skipped = array_values($this->skippedTeachers);
        usort($skipped, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'source' => $this->sourceLabel,
            'target' => $this->targetLabel,
            'weeks' => $this->weeks,
            'calendars' => $this->calendars,
            'programs' => array_values($this->programs),
            'skippedTeachers' => $skipped,
            'shiftedDates' => $this->shiftedDates,
        ];
    }

    private static function range(\DateTimeImmutable $start, ?\DateTimeImmutable $end): string
    {
        return null === $end ? $start->format('d/m/Y') : $start->format('d/m/Y').' → '.$end->format('d/m/Y');
    }
}
