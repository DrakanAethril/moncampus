<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

use App\Entity\Enterprise;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AlternanceImportAction;

/**
 * One line of the file after it has been confronted with the database: who it points at, what it
 * would create, and everything wrong with it.
 *
 * The three "resolved or null" pairs read the same way throughout: a null $enterprise with a
 * non-empty name in $row means "would be created", and so does a null $tutor. $student is the
 * exception - a null there is always a blocking finding, never something to create, because the
 * import may not invent an alternant.
 */
final readonly class AnalyzedRow
{
    /** @param list<ImportIssue> $issues */
    public function __construct(
        public ContractRow $row,
        public AlternanceImportAction $action,
        public array $issues,
        public ?User $student = null,
        public ?Program $program = null,
        public ?Enterprise $enterprise = null,
        public ?User $tutor = null,
        public ?ContractPeriod $period = null,
        /** The alternance this line duplicates, when the student already holds one. */
        public bool $alreadyImported = false,
        /**
         * The personal address to write onto a student who has none - null when they already have
         * one (never overwritten), when the file gives none, or when some other account holds it.
         */
        public ?string $studentEmailToFill = null,
    ) {
    }

    public function isEnterpriseNew(): bool
    {
        return null === $this->enterprise && '' !== $this->row->enterpriseName;
    }

    public function isTutorNew(): bool
    {
        return null === $this->tutor && '' !== $this->row->tutorEmail;
    }

    public function isBlocked(): bool
    {
        return AlternanceImportAction::Blocked === $this->action;
    }

    /** @return list<ImportIssue> */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $issue): bool => $issue->isBlocking()));
    }

    /** @return list<ImportIssue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $issue): bool => $issue->isWarning()));
    }

    /** @return list<ImportIssue> */
    public function notes(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $issue): bool => !$issue->isBlocking() && !$issue->isWarning()));
    }
}
