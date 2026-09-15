<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

/**
 * One machine as the file writes it - nothing resolved, nothing checked against the inventory. That
 * is App\Service\LaptopImport\LaptopImportAnalyzer's work.
 *
 * `line` is the file's own line number (the header being line 1), so every message the operator
 * reads names a line they can find in their spreadsheet.
 *
 * `replacementValue` stays the raw cell rather than a number: a sum the file spells « 500 € » or
 * « 1 200,50 » has to be shown back as it was written when it is refused, and only the analysis
 * decides whether it can be read at all.
 */
final readonly class LaptopRow
{
    public function __construct(
        public int $line,
        public string $assetTag,
        public string $serialNumber,
        public string $brand,
        public string $model,
        public string $replacementValue,
    ) {
    }

    /**
     * The rows live in the session between the verification screen and the writing - never the
     * uploaded file itself, which has served its purpose the moment it has been read.
     *
     * @return array{line: int, assetTag: string, serialNumber: string, brand: string, model: string, replacementValue: string}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'assetTag' => $this->assetTag,
            'serialNumber' => $this->serialNumber,
            'brand' => $this->brand,
            'model' => $this->model,
            'replacementValue' => $this->replacementValue,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): string => \is_string($data[$key] ?? null) ? $data[$key] : '';

        return new self(
            \is_int($data['line'] ?? null) ? $data['line'] : 0,
            $string('assetTag'),
            $string('serialNumber'),
            $string('brand'),
            $string('model'),
            $string('replacementValue'),
        );
    }

    /** « HP PAVILION », or an empty string when the file names neither. */
    public function deviceLabel(): string
    {
        return trim($this->brand.' '.$this->model);
    }
}
