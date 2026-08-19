<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * One student as the file writes them - nothing resolved, nothing normalised, nothing checked
 * against the database. That is App\Service\ClassImport\ClassImportAnalyzer's work.
 *
 * `line` is the file's own line number (the header being line 1), so every message the operator
 * reads names a line they can find in their spreadsheet.
 */
final readonly class StudentRow
{
    /** @param list<FreeCell> $freeCells */
    public function __construct(
        public int $line,
        public string $lastname,
        public string $firstname,
        public string $email,
        public array $freeCells,
    ) {
    }

    /**
     * The rows live in the session between the verification screen and the writing - never the
     * uploaded file itself, which has served its purpose the moment it has been read.
     *
     * @return array{line: int, lastname: string, firstname: string, email: string, freeCells: list<array{header: string, foldedHeader: string, value: string}>}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'lastname' => $this->lastname,
            'firstname' => $this->firstname,
            'email' => $this->email,
            'freeCells' => array_map(static fn (FreeCell $cell): array => $cell->toArray(), $this->freeCells),
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $cells = \is_array($data['freeCells'] ?? null) ? $data['freeCells'] : [];

        return new self(
            \is_int($data['line'] ?? null) ? $data['line'] : 0,
            \is_string($data['lastname'] ?? null) ? $data['lastname'] : '',
            \is_string($data['firstname'] ?? null) ? $data['firstname'] : '',
            \is_string($data['email'] ?? null) ? $data['email'] : '',
            array_values(array_map(
                static fn (mixed $cell): FreeCell => FreeCell::fromArray(\is_array($cell) ? $cell : []),
                $cells,
            )),
        );
    }
}
