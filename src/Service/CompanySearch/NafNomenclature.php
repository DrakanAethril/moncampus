<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function Symfony\Component\String\u;

/**
 * The INSEE's NAF rév. 2 (732 sub-classes), read from `resources/naf/naf-rev2.csv` - the
 * nomenclature file the INSEE publishes under Licence Ouverte, converted once to CSV. The register
 * only ever answers codes; this is how a student reads « Conseil en systèmes et logiciels
 * informatiques » instead of 62.02A, and how the advanced filter completes on a word.
 *
 * Kept in memory once loaded: it is a constant table, and the same in every request a worker
 * serves - there is nothing to reset.
 *
 * NAF 2025 is coming to SIRENE; the day it does, a second file and a correspondence table go next
 * to this one. Nothing else has codes written in it.
 */
class NafNomenclature
{
    /** @var array<string, string>|null code => label */
    private ?array $labels = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/naf/naf-rev2.csv')]
        private readonly string $file,
    ) {
    }

    public function label(?string $code): ?string
    {
        if (null === $code) {
            return null;
        }

        return $this->labels()[mb_strtoupper(trim($code))] ?? null;
    }

    public function exists(string $code): bool
    {
        return null !== $this->label($code);
    }

    /**
     * Codes whose code or label holds every word typed, accents and case aside - « logiciel » gives
     * the four software codes, « 62.0 » the 62.0x family.
     *
     * @return list<array{code: string, label: string}>
     */
    public function search(string $term, int $limit = 20): array
    {
        $words = array_filter(preg_split('/\s+/', $this->fold($term)) ?: [], static fn (string $word): bool => '' !== $word);
        if ([] === $words) {
            return [];
        }

        $matches = [];
        foreach ($this->labels() as $code => $label) {
            $haystack = $this->fold($code.' '.$label);
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            $matches[] = ['code' => $code, 'label' => $label];
            if (\count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        if (null !== $this->labels) {
            return $this->labels;
        }

        $labels = [];
        $handle = fopen($this->file, 'r');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('NAF nomenclature missing: %s', $this->file));
        }

        fgetcsv($handle, escape: '');
        while (false !== ($row = fgetcsv($handle, escape: ''))) {
            if (isset($row[0], $row[1])) {
                $labels[$row[0]] = $row[1];
            }
        }
        fclose($handle);

        return $this->labels = $labels;
    }

    private function fold(string $text): string
    {
        return u($text)->ascii()->lower()->toString();
    }
}
