<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

/**
 * One line of the spreadsheet, as typed - every field a trimmed string, nothing resolved yet.
 *
 * Deliberately dumb and serialisable (see toArray()/fromArray()): the upload step parses the file
 * once and parks these in the session, and the confirmation step re-runs the whole analysis from
 * them rather than from a second upload. That is what makes "ce que l'opérateur a validé" and
 * "ce qui est écrit" the same data - the file itself never has to be read twice.
 */
final readonly class ContractRow
{
    public function __construct(
        /** 1-based line number in the worksheet, header included - what the operator sees in Excel. */
        public int $line,
        public string $classCode,
        public string $studentName,
        public string $studentEmail,
        public string $studentPhone,
        public string $enterpriseName,
        public string $enterpriseAddress,
        public string $tutorName,
        public string $tutorPhone,
        public string $tutorMobile,
        public string $tutorEmail,
        public string $observations,
    ) {
    }

    /** The one phone number kept for the tutor - User holds a single field, the file two columns. */
    public function tutorBestPhone(): string
    {
        return '' !== $this->tutorMobile ? $this->tutorMobile : $this->tutorPhone;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'classCode' => $this->classCode,
            'studentName' => $this->studentName,
            'studentEmail' => $this->studentEmail,
            'studentPhone' => $this->studentPhone,
            'enterpriseName' => $this->enterpriseName,
            'enterpriseAddress' => $this->enterpriseAddress,
            'tutorName' => $this->tutorName,
            'tutorPhone' => $this->tutorPhone,
            'tutorMobile' => $this->tutorMobile,
            'tutorEmail' => $this->tutorEmail,
            'observations' => $this->observations,
        ];
    }

    /** @param array<array-key, mixed> $data as produced by toArray(), read back out of the session */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): string => \is_string($data[$key] ?? null) ? $data[$key] : '';

        return new self(
            \is_int($data['line'] ?? null) ? $data['line'] : 0,
            $string('classCode'),
            $string('studentName'),
            $string('studentEmail'),
            $string('studentPhone'),
            $string('enterpriseName'),
            $string('enterpriseAddress'),
            $string('tutorName'),
            $string('tutorPhone'),
            $string('tutorMobile'),
            $string('tutorEmail'),
            $string('observations'),
        );
    }
}
