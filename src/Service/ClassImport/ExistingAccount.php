<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * An account that already carries a line's name, flattened to primitives so the analysis can be
 * tested without building a class, a school year and a directory.
 *
 * Everything on it is there to let the operator tell two namesakes apart on screen 4 - login,
 * class, state, contact address, creation date - or to let the analysis refuse a line: an account
 * of the wrong type, an address already taken.
 */
final readonly class ExistingAccount
{
    /**
     * @param string    $userType    'student', 'teacher', … as resolved from the account's directory roles
     * @param list<int> $optionIds   options this student already carries in the destination class
     * @param list<int> $modalityIds modalities they already carry there
     */
    public function __construct(
        public int $id,
        public string $login,
        public string $firstname,
        public string $lastname,
        public string $userType,
        public bool $active,
        public ?string $contactEmail,
        public bool $inDestinationProgram,
        public bool $inAnotherProgramOfTheSameYear,
        public string $programLabel,
        public ?\DateTimeImmutable $createdAt,
        public array $optionIds = [],
        public array $modalityIds = [],
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    public function isStudent(): bool
    {
        return 'student' === $this->userType;
    }

    public function carries(KnownValue $value): bool
    {
        return \in_array($value->id, $value->modality ? $this->modalityIds : $this->optionIds, true);
    }

    /** The key the account type is written under - see App\Service\ClassImport\ImportIssue. */
    public function userTypeLabelKey(): string
    {
        return 'accountType'.str_replace(' ', '', ucwords(str_replace('-', ' ', $this->userType))).'Label';
    }
}
