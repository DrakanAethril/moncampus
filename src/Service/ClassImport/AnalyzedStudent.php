<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Enum\ClassImportAction;

/**
 * The verdict on one line: what the import would do with it, what it would write, and everything
 * it has to say about it first.
 *
 * `firstname`/`lastname` are the normalised spellings - the ones that will actually be written -
 * while `rawFirstname`/`rawLastname` are the file's. The screen shows both when they differ,
 * because the directory takes these over read-only afterwards and a bad normalisation cannot be
 * repaired from the application.
 */
final readonly class AnalyzedStudent
{
    /**
     * @param list<ImportIssue>     $issues
     * @param list<KnownValue>      $valuesToAdd options and modalities the import would add
     * @param list<ExistingAccount> $candidates  namesakes to choose between, empty once decided
     * @param ExistingAccount|null  $account     the account this line resolves to, null for a creation
     * @param bool                  $fillsEmail  whether the file's address will actually be written
     */
    public function __construct(
        public int $line,
        public string $firstname,
        public string $lastname,
        public string $rawFirstname,
        public string $rawLastname,
        public ?string $email,
        public ClassImportAction $action,
        public array $issues,
        public array $valuesToAdd = [],
        public array $candidates = [],
        public ?ExistingAccount $account = null,
        public bool $fillsEmail = false,
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    public function rawDisplayName(): string
    {
        return trim($this->rawFirstname.' '.$this->rawLastname);
    }

    public function nameWasNormalized(): bool
    {
        return $this->displayName() !== $this->rawDisplayName();
    }

    /** @return list<ImportIssue> */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $issue): bool => $issue->isBlocking()));
    }

    /** Everything that is not blocking - a warning or a note, both of which the screen shows in place. */
    /** @return list<ImportIssue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $issue): bool => !$issue->isBlocking()));
    }

    /** Whether this line alone is enough to refuse the whole file. */
    public function isBlocking(): bool
    {
        return \in_array($this->action, [ClassImportAction::Blocked, ClassImportAction::Decide], true);
    }

    /** A line that would leave the database exactly as it found it is not an import. */
    public function writes(): bool
    {
        return match ($this->action) {
            ClassImportAction::Create, ClassImportAction::Attach => true,
            ClassImportAction::Update => $this->fillsEmail || [] !== $this->valuesToAdd,
            default => false,
        };
    }

    /**
     * A single namesake, active and a student: the case a whole promotion moving up a year
     * produces, and the only one the header's bulk action may answer. A disabled account (the
     * answer would reactivate it) and an ambiguity (several namesakes) are read one by one.
     */
    public function isObviousDecision(): bool
    {
        return ClassImportAction::Decide === $this->action
            && 1 === \count($this->candidates)
            && $this->candidates[0]->active
            && $this->candidates[0]->isStudent();
    }
}
