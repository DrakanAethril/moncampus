<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SchoolYear;
use App\Service\Crm\CrmPieceValue;
use App\Service\Crm\CrmSearch;
use App\Service\Crm\CrmSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** What the CRM's templates read: a year's label, a piece's value, a name with the words found. */
class CrmExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('crm_value', [CrmPieceValue::class, 'format']),
            new TwigFunction('crm_short_value', [CrmPieceValue::class, 'shortFormat']),
            new TwigFunction('crm_program_name', [CrmSettings::class, 'dossierProgramName']),
            new TwigFunction('crm_mark', $this->mark(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('crm_year', static fn (?SchoolYear $year): string => null === $year ? '' : CrmSettings::yearLabel($year)),
        ];
    }

    /**
     * The name with the start of each word typed marked (« <mark>Dup</mark>ont »), escaped.
     *
     * @param list<string> $words
     */
    public function mark(string $text, array $words): string
    {
        $parts = preg_split('/([\s\-\']+)/u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';
        foreach ($parts as $part) {
            $normal = CrmSearch::normalize($part);
            $best = null;
            foreach ($words as $word) {
                if ('' !== $word && str_starts_with($normal, $word) && (null === $best || mb_strlen($word) > mb_strlen($best))) {
                    $best = $word;
                }
            }
            $escape = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $out .= null === $best
                ? $escape($part)
                : '<mark>'.$escape(mb_substr($part, 0, mb_strlen($best))).'</mark>'.$escape(mb_substr($part, mb_strlen($best)));
        }

        return $out;
    }
}
