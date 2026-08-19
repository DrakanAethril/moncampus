<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * One cell of a column that is neither `nom`, `prenom` nor `mail` - so, an option or a modality of
 * the destination class, written in clear.
 *
 * Both spellings of the header travel with the value because both are used to resolve it: the
 * folded one decides whether the column names an option or a modality outright, the raw one is
 * what the verification screen shows the operator.
 */
final readonly class FreeCell
{
    public function __construct(
        public string $header,
        public string $foldedHeader,
        public string $value,
    ) {
    }

    /** @return array{header: string, foldedHeader: string, value: string} */
    public function toArray(): array
    {
        return ['header' => $this->header, 'foldedHeader' => $this->foldedHeader, 'value' => $this->value];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            \is_string($data['header'] ?? null) ? $data['header'] : '',
            \is_string($data['foldedHeader'] ?? null) ? $data['foldedHeader'] : '',
            \is_string($data['value'] ?? null) ? $data['value'] : '',
        );
    }
}
