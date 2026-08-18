<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * One option or one modality of the destination class, as the analysis needs it: a label to show,
 * an id to write, and the folded spellings a cell may use to name it (its name and its short name).
 *
 * The two are one type on purpose - §2.1 treats them identically, the link table is unique on
 * (program, student, value) in both cases, and the import adds what is missing without ever
 * removing. Only `modality` says which table the executor writes to.
 */
final readonly class KnownValue
{
    /** @param list<string> $aliases folded spellings that resolve to this value */
    public function __construct(
        public int $id,
        public string $label,
        public bool $modality,
        public array $aliases,
    ) {
    }

    public function matches(string $foldedValue): bool
    {
        return \in_array($foldedValue, $this->aliases, true);
    }
}
