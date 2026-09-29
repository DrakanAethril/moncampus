<?php

declare(strict_types=1);

namespace App\Service\Sirene;

use App\Entity\Enterprise;
use App\Entity\User;
use App\Repository\EnterpriseRepository;

/**
 * The SIRET candidates for an employer, in the order a person should look at them.
 *
 * The algorithm is the one measured on 2026-09-29 over the 47 employers of the CFC file
 * (design/sources/import-alternances/mesure-siret/v2.py; results in
 * design/validated/siret-entreprises.md, §2). One query on the raw name finds about half of them,
 * so it cascades, stopping as soon as a candidate has the right name, the right place and at least
 * the street:
 *
 *  1. every variant of the name (whole, each part around « / » or « - », each without its legal
 *     form) on the postcode, then on the département;
 *  2. failing a right-name-right-place candidate, the most distinctive single word on the postcode -
 *     how « Cabinet Lepetit Arvid » finds the sole trader Arvid Lepetit;
 *  3. failing that, the street line with its number on the postcode - the neighbours at the same
 *     door, how an employer known under another name is found at all.
 *
 * **Nothing here decides** (R1): no threshold triggers a write, and the finder returns candidates
 * to a screen, never to a setter. ACOM → Cabinet Robert, the measure's one false positive, came out
 * of step 2 with no address in common: it is listed, and listed without an address badge.
 *
 * Only the employer's name and address are sent to the État, never anything about an alternant.
 */
class SiretCandidateFinder
{
    /** Half the searched words found in one of the establishment's names makes « the right name ». */
    public const float NAME_MATCH = 0.5;

    /** A ceiling on one cascade: a screen is waiting, at 7 calls a second. */
    private const int MAX_CALLS = 12;

    /** How many candidates the screen shows. */
    private const int SHOWN = 10;

    private const array LEGAL_FORMS = ['SAS', 'SARL', 'EURL', 'SA', 'SASU', 'SCI', 'SOCIETE', 'STE'];

    /** Words too common to single an employer out (step 2) - the measure's own list. */
    private const array GENERIC_WORDS = [
        'CABINET', 'GROUP', 'GROUPE', 'GROUPES', 'NOUVELLE', 'SERVICE', 'SERVICES', 'CENTRE', 'COMMERCIAL',
        'EXPERTISE', 'COMPTABLE', 'COMPTEBLE', 'ASSOCIATION', 'FRANCE', 'LIMOGES', 'GESTION', 'CONSEIL',
        'AUDIT', 'ET', 'DE', 'DU', 'LA', 'LE', 'DES',
    ];

    /** Street-type and filler words that say nothing about which street it is. */
    private const array STREET_STOP_WORDS = [
        'RUE', 'AV', 'AVENUE', 'DE', 'DU', 'LA', 'LE', 'DES', 'ROUTE', 'BD', 'BOULEVARD', 'PL', 'PLACE',
        'IMP', 'IMPASSE', 'QU', 'QUAI', 'CHEMIN', 'D', 'L', 'ZI', 'ZA', 'PARC', 'NORD', 'SUD', 'CS', 'BP',
        'BAT', 'B',
    ];

    public function __construct(
        private readonly RechercheEntreprisesClient $client,
        private readonly EnterpriseRepository $enterprises,
    ) {
    }

    /**
     * The cascade, for the SIRET screen's « Propositions ».
     *
     * @return list<RankedCandidate>
     *
     * @throws SireneUnavailableException
     */
    public function propose(Enterprise $enterprise, ?User $viewer = null): array
    {
        $location = EmployerLocation::read($enterprise->getAddress());
        $name = $enterprise->getName();
        [$variants, $distinctWords] = $this->variants($name);
        $narrowings = $this->narrowings($location);
        $calls = 0;
        $found = [];

        // 1. The name's variants, on the postcode first.
        foreach ($narrowings as [$postalCode, $department]) {
            foreach ($variants as $variant) {
                if ($calls >= self::MAX_CALLS) {
                    break 2;
                }
                array_push($found, ...$this->collect($variant, $postalCode, $department, self::words($variant), $location, $calls));
                if ($this->hasStreetMatch($found)) {
                    break 2;
                }
            }
        }

        // 2. The most distinctive words, on the narrowest place. A hit on such a word counts as
        //    the right name - the API found it on that word, and the address will say the rest.
        if (!$this->hasLikely($found)) {
            [$postalCode, $department] = $narrowings[0];
            foreach ($distinctWords as $word) {
                if ($calls >= self::MAX_CALLS) {
                    break;
                }
                foreach ($this->collect($word, $postalCode, $department, [$word], $location, $calls) as $candidate) {
                    $found[] = $candidate->withNameScore(max($candidate->nameScore, self::NAME_MATCH));
                }
            }
        }

        // 3. The street line, number included: the establishments at that door, whatever their name.
        if (!$this->hasLikely($found) && null !== $location->postalCode) {
            foreach ($location->streetLines as $line) {
                if ($calls >= self::MAX_CALLS) {
                    break;
                }
                if (1 !== preg_match('/\d/', $line) || 1 === preg_match('/^(CS|BP)\b/i', $line)) {
                    continue;
                }
                foreach ($this->collect($line, $location->postalCode, null, self::words($name), $location, $calls) as $candidate) {
                    if ($candidate->streetScore >= 3) {
                        $found[] = $candidate;
                    }
                }
            }
        }

        return $this->finish($found, $enterprise, $viewer);
    }

    /**
     * « Chercher autrement »: one query typed by the person - a name, a street, a SIREN or a SIRET -
     * instead of the cascade. What rattrapes the measure's failures (ENSEMBLE, FCMB, ACOM).
     *
     * A SIRET is looked up as such, closed or not; a SIREN lists the company's open establishments
     * wherever they are; anything else is searched on the postcode given, if any.
     *
     * @return list<RankedCandidate>
     *
     * @throws SireneUnavailableException
     */
    public function search(Enterprise $enterprise, string $query, ?string $postalCode, ?User $viewer = null): array
    {
        $location = EmployerLocation::read($enterprise->getAddress());
        $nameWords = self::words($enterprise->getName());
        $digits = Siret::normalize($query);

        if (1 === preg_match('/^\d{14}$/', $digits)) {
            $establishment = $this->client->establishment($digits);
            $found = null !== $establishment ? [$this->rank($establishment, $nameWords, $location)] : [];

            return $this->finish($found, $enterprise, $viewer);
        }

        $isSiren = 1 === preg_match('/^\d{9}$/', $digits);
        $queryWords = self::words($query);
        $found = [];
        foreach ($this->client->search($isSiren ? $digits : $query, $isSiren ? null : $postalCode) as $establishment) {
            $candidate = $this->rank($establishment, $nameWords, $location);
            // The typed words count as much as the fiche's name: « FCMB » is the name somebody knew.
            $found[] = $candidate->withNameScore(max($candidate->nameScore, $this->nameScore($queryWords, $establishment)));
        }

        return $this->finish($found, $enterprise, $viewer);
    }

    /**
     * @param list<string> $queryWords
     *
     * @return list<RankedCandidate>
     */
    private function collect(string $query, ?string $postalCode, ?string $department, array $queryWords, EmployerLocation $location, int &$calls): array
    {
        ++$calls;
        $candidates = [];
        foreach ($this->client->search($query, $postalCode, $department) as $establishment) {
            $candidates[] = $this->rank($establishment, $queryWords, $location);
        }

        return $candidates;
    }

    /** @param list<string> $queryWords */
    private function rank(EstablishmentCandidate $establishment, array $queryWords, EmployerLocation $location): RankedCandidate
    {
        [$streetScore, $streetWords] = $this->streetScore($location->streetLines, $establishment->address);

        return new RankedCandidate(
            establishment: $establishment,
            nameScore: $this->nameScore($queryWords, $establishment),
            streetScore: $streetScore,
            streetWords: $streetWords,
            samePlace: $location->isSamePlace($establishment->postalCode),
            exactName: implode(' ', self::words($establishment->legalName ?? $establishment->fullName)) === implode(' ', $queryWords),
        );
    }

    /**
     * Deduplicated by SIRET (the best reading of each kept), told which share a company and which
     * another fiche already carries, ordered, and cut to what the screen shows.
     *
     * @param list<RankedCandidate> $found
     *
     * @return list<RankedCandidate>
     */
    private function finish(array $found, Enterprise $enterprise, ?User $viewer): array
    {
        $best = [];
        foreach ($found as $candidate) {
            $siret = $candidate->establishment->siret;
            $kept = $best[$siret] ?? null;
            if (null === $kept || [$candidate->nameScore, $candidate->streetScore] > [$kept->nameScore, $kept->streetScore]) {
                $best[$siret] = $candidate;
            }
        }

        usort($best, static fn (RankedCandidate $a, RankedCandidate $b): int => [$b->isLikely(), $b->streetScore, $b->exactName, $b->nameScore]
            <=> [$a->isLikely(), $a->streetScore, $a->exactName, $a->nameScore]);
        $best = \array_slice($best, 0, self::SHOWN);

        $sirenCounts = array_count_values(array_map(static fn (RankedCandidate $c): string => $c->establishment->siren, $best));
        $linked = $this->enterprises->findOthersBySiret(
            array_map(static fn (RankedCandidate $c): string => $c->establishment->siret, $best),
            $enterprise,
            $viewer,
        );

        return array_map(
            static fn (RankedCandidate $c): RankedCandidate => $c->with(
                sameCompanyElsewhere: ($sirenCounts[$c->establishment->siren] ?? 0) > 1 && !($c->samePlace && $c->streetScore >= 3),
                linkedTo: $linked[$c->establishment->siret] ?? null,
            ),
            $best,
        );
    }

    /** @param list<RankedCandidate> $found */
    private function hasStreetMatch(array $found): bool
    {
        foreach ($found as $candidate) {
            if ($candidate->isLikely() && $candidate->streetScore >= 2) {
                return true;
            }
        }

        return false;
    }

    /** @param list<RankedCandidate> $found */
    private function hasLikely(array $found): bool
    {
        foreach ($found as $candidate) {
            if ($candidate->isLikely()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name's variants, then its distinctive words longest first.
     *
     * @return array{list<string>, list<string>}
     */
    private function variants(string $name): array
    {
        $parts = [$name];
        foreach ([' / ', ' - '] as $separator) {
            foreach (explode($separator, $name) as $part) {
                $part = trim($part);
                if ('' !== $part && $part !== $name) {
                    $parts[] = $part;
                }
            }
        }

        $variants = [];
        foreach ($parts as $part) {
            foreach ([$part, implode(' ', self::words($part))] as $variant) {
                if ('' !== $variant && !\in_array($variant, $variants, true)) {
                    $variants[] = $variant;
                }
            }
        }

        $distinct = array_values(array_unique(array_filter(
            self::words($name),
            static fn (string $word): bool => mb_strlen($word) >= 4 && !\in_array($word, self::GENERIC_WORDS, true),
        )));
        usort($distinct, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return [$variants, $distinct];
    }

    /**
     * Where to narrow each round: the postcode then the département; the département alone for a
     * CEDEX; nowhere when the address gives no postcode.
     *
     * @return non-empty-list<array{?string, ?string}>
     */
    private function narrowings(EmployerLocation $location): array
    {
        $department = $location->department();

        if (null === $location->postalCode) {
            return [[null, null]];
        }

        if ($location->cedex) {
            return [[null, $department]];
        }

        return null !== $department ? [[$location->postalCode, null], [null, $department]] : [[$location->postalCode, null]];
    }

    /** @param list<string> $queryWords */
    private function nameScore(array $queryWords, EstablishmentCandidate $establishment): float
    {
        $query = array_unique($queryWords);
        if ([] === $query) {
            return 0.0;
        }

        $best = 0.0;
        foreach ($establishment->names() as $name) {
            $words = array_unique(self::words($name));
            if ([] !== $words) {
                $best = max($best, \count(array_intersect($query, $words)) / \count($query));
            }
        }

        return $best;
    }

    /**
     * 2 for a street number in common, plus up to 2 street words in common, on the employer's best
     * line - and how many words that line shared.
     *
     * @param list<string> $streetLines
     *
     * @return array{int, int}
     */
    private function streetScore(array $streetLines, string $address): array
    {
        // The establishment's street part only: its postcode and town would match every neighbour.
        $street = preg_replace('/\b\d{5}\b.*$/', '', self::normalize($address)) ?? '';
        $addressWords = array_filter(explode(' ', $street), static fn (string $word): bool => '' !== $word);
        preg_match_all('/\b(\d+)/', $street, $matches);
        $addressNumbers = $matches[1];

        $best = [0, 0];
        foreach ($streetLines as $line) {
            $normalized = self::normalize($line);
            $words = array_diff(array_filter(explode(' ', $normalized), static fn (string $word): bool => '' !== $word), self::STREET_STOP_WORDS);
            preg_match_all('/\b(\d+)/', $normalized, $lineMatches);

            $score = [] !== array_intersect($lineMatches[1], $addressNumbers) ? 2 : 0;
            $shared = \count(array_unique(array_intersect(
                array_filter($words, static fn (string $word): bool => !ctype_digit($word)),
                $addressWords,
            )));
            $score += min($shared, 2);

            if ([$score, $shared] > $best) {
                $best = [$score, $shared];
            }
        }

        return $best;
    }

    /**
     * A name's words, upper case, without accents, punctuation or legal form.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = [];
        foreach (explode(' ', self::normalize($text)) as $word) {
            if ('' !== $word && !\in_array($word, self::LEGAL_FORMS, true)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    private static function normalize(string $text): string
    {
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_KD);
        $ascii = preg_replace('/[^\x00-\x7F]/u', '', \is_string($decomposed) ? $decomposed : $text) ?? '';

        return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($ascii)) ?? '');
    }
}
