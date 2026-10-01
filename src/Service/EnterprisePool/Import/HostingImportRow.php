<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool\Import;

/**
 * One line of « Importer l'historique », as the file says it - nothing resolved yet. Lives in the
 * session between the analysis and the import, hence toArray()/fromArray().
 */
final readonly class HostingImportRow
{
    /** The columns of the model file, in its order - also the only headers recognised. */
    public const array COLUMNS = [
        'type', 'annee', 'filiere', 'option', 'etudiant', 'entreprise', 'siret', 'adresse',
        'code_postal', 'ville', 'tuteur_nom', 'tuteur_fonction', 'tuteur_email', 'tuteur_telephone', 'missions',
    ];

    /**
     * @param array<string, string> $values keyed by COLUMNS
     */
    public function __construct(
        public int $line,
        public array $values,
    ) {
    }

    public function get(string $column): string
    {
        return trim($this->values[$column] ?? '');
    }

    /** @return array{line: int, values: array<string, string>} */
    public function toArray(): array
    {
        return ['line' => $this->line, 'values' => $this->values];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $values = [];
        foreach (\is_array($data['values'] ?? null) ? $data['values'] : [] as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return new self(is_numeric($data['line'] ?? null) ? (int) $data['line'] : 0, $values);
    }
}
