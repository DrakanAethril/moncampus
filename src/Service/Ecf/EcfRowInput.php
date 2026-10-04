<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfEvaluationRow;
use Symfony\Component\HttpFoundation\Request;

/**
 * One submitted line of an evaluation table, typed at the boundary.
 */
final readonly class EcfRowInput
{
    /** @param list<int> $competences */
    public function __construct(
        public string $description,
        public ?\DateTimeImmutable $evaluatedOn,
        public array $competences,
    ) {
    }

    public function isBlank(): bool
    {
        return '' === trim($this->description) && null === $this->evaluatedOn && [] === $this->competences;
    }

    /**
     * The `rows[i][description|date|competences][]` fields of a sheet, in submitted order.
     *
     * @return list<self>
     */
    public static function listFromRequest(Request $request, string $key = 'rows'): array
    {
        $raw = $request->request->all()[$key] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $description = $entry['description'] ?? '';
            $competences = [];
            foreach (\is_array($entry['competences'] ?? null) ? $entry['competences'] : [] as $number) {
                if (is_numeric($number) && (int) $number >= 1 && (int) $number <= EcfEvaluationRow::MAX_COMPETENCE) {
                    $competences[] = (int) $number;
                }
            }

            $rows[] = new self(
                \is_string($description) ? str_replace("\r\n", "\n", $description) : '',
                self::date($entry['date'] ?? null),
                $competences,
            );
        }

        return $rows;
    }

    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
