<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmDossier;
use App\Entity\SchoolYear;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;

/**
 * « Rechercher un dossier par nom ou prénom »: every word typed starts a word of the name (« lea
 * dup » finds DUPONT Léa), accents and case aside, in the years opened in the CRM and the formations
 * followed. The work year's dossiers come first, then by situation (active, waiting, closed), year
 * and name.
 */
final readonly class CrmSearch
{
    public function __construct(
        private CrmDossierRepository $dossiers,
        private CrmSettings $settings,
    ) {
    }

    /** @return list<string> */
    public static function words(string $query): array
    {
        return array_values(array_filter(preg_split('/[\s\-\']+/u', self::normalize($query)) ?: [], static fn (string $w): bool => '' !== $w));
    }

    public static function normalize(string $text): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);

        return \is_string($ascii) ? $ascii : mb_strtolower($text);
    }

    /** Whether every word typed starts a word of the name. */
    public static function matches(string $name, array $words): bool
    {
        $nameWords = self::words($name);
        foreach ($words as $word) {
            if ([] === array_filter($nameWords, static fn (string $w): bool => str_starts_with($w, $word))) {
                return false;
            }
        }

        return [] !== $words;
    }

    /** @return list<CrmDossier> */
    public function find(string $query, ?SchoolYear $workYear): array
    {
        $words = self::words($query);
        $years = $this->settings->openedYears();
        $hits = array_values(array_filter(
            $this->dossiers->search($years, $words),
            fn (CrmDossier $d): bool => $this->settings->isFollowed($d->getProgram()) && self::matches($d->getCandidate()->getFullName(), $words),
        ));

        $rank = static fn (CrmSituation $s): int => CrmSituation::Active === $s ? 0 : ($s->isWaiting() ? 1 : 2);
        usort($hits, static fn (CrmDossier $a, CrmDossier $b): int => [
            $a->getProgram()->getSchoolYear() === $workYear ? 0 : 1,
            $rank($a->getSituation()),
            $a->getProgram()->getSchoolYear()?->getStartDate()?->format('Y'),
            self::normalize($a->getCandidate()->getFullName()),
        ] <=> [
            $b->getProgram()->getSchoolYear() === $workYear ? 0 : 1,
            $rank($b->getSituation()),
            $b->getProgram()->getSchoolYear()?->getStartDate()?->format('Y'),
            self::normalize($b->getCandidate()->getFullName()),
        ]);

        return $hits;
    }
}
