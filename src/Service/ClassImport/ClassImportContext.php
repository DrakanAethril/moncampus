<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * Everything the analysis needs to know about the database, read once and flattened to primitives:
 * the destination class, its options and modalities, the accounts that already carry each name, and
 * who holds which contact address.
 *
 * Built by App\Service\ClassImport\ClassImportContextFactory. The analysis itself never touches
 * Doctrine, which is what makes §2.1, §3 and §4 testable one case at a time.
 */
final readonly class ClassImportContext
{
    /**
     * @param list<KnownValue>                          $options
     * @param list<KnownValue>                          $modalities
     * @param array<string, list<ExistingAccount>>      $accountsByNameKey indexed by NameKey::$value
     * @param array<string, array{int, string}>         $emailOwners       folded address => [account id, "login (class)"]
     */
    public function __construct(
        public string $programLabel,
        public bool $testProgram,
        public array $options,
        public array $modalities,
        public array $accountsByNameKey,
        public array $emailOwners,
    ) {
    }

    /** @return list<ExistingAccount> */
    public function accountsNamed(NameKey $key): array
    {
        return $this->accountsByNameKey[$key->value] ?? [];
    }

    /** @return list<KnownValue> options first, then modalities - the order the messages list them in */
    public function values(): array
    {
        return [...$this->options, ...$this->modalities];
    }

    /** Every value of the class, named, for the message that refuses an unknown one. */
    public function valueLabels(): string
    {
        return implode(', ', array_map(static fn (KnownValue $value): string => $value->label, $this->values()));
    }

    /** @return array{int, string}|null */
    public function ownerOfEmail(string $email): ?array
    {
        return $this->emailOwners[mb_strtolower(trim($email))] ?? null;
    }
}
