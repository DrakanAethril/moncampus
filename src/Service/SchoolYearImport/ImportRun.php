<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\EvaluationPeriodGroup;
use App\Entity\PeriodGroup;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\SchoolYearImportBlock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One pass of the import: the planner's dry run (nothing persisted, `dryRun`) or the importer's
 * (everything persisted, nothing flushed - the importer flushes). Every copier reads the same
 * questions from it, so the plan shown on screen 3 and the rows written are the same code.
 *
 * The calendar maps are filled by the calendar copiers before the formations are copied (§7):
 * a source calendar id → the target calendar it was recognised as or created as (null in a dry
 * run for one that would be created) - present in the map means « there will be one ».
 */
final class ImportRun
{
    /** @var array<int, PeriodGroup|null> */
    public array $periodGroups = [];

    /** @var array<int, EvaluationPeriodGroup|null> */
    public array $evaluationGroups = [];

    /** The target year was opened to the CRM by this run - the next formation does not open it again. */
    public bool $crmYearOpened = false;

    public function __construct(
        public readonly SchoolYear $source,
        public readonly SchoolYear $target,
        public readonly SchoolYearImportChoices $choices,
        public readonly SchoolYearDateShift $shift,
        public readonly SchoolYearNameRewriter $names,
        public readonly SchoolYearImportData $data,
        public readonly SchoolYearImportReport $report,
        public readonly EntityManagerInterface $entityManager,
        public readonly bool $dryRun,
        public readonly \DateTimeImmutable $now,
        public readonly ?User $actor,
    ) {
    }

    public function has(SchoolYearImportBlock $block): bool
    {
        return $this->choices->has($block);
    }

    /**
     * The target formation to write on - null in a dry run, where a formation already there is only
     * read: the plan must leave the unit of work as it found it.
     */
    public function writable(ProgramPair $pair): ?\App\Entity\Program
    {
        return $this->dryRun ? null : $pair->target;
    }

    /**
     * Persists a new row, stamped with its author when it carries AuditableTrait - or, in a dry run,
     * does nothing: the caller has only counted it.
     */
    public function persist(object $entity): void
    {
        if (!$this->dryRun) {
            $this->stamp($entity);
            $this->entityManager->persist($entity);
        }
    }

    /** createdBy on the rows that carry AuditableTrait. */
    public function stamp(object $entity): void
    {
        if (method_exists($entity, 'setCreatedBy')) {
            $entity->setCreatedBy($this->actor);
        }
    }
}
