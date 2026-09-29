<?php

declare(strict_types=1);

namespace App\Service\Sirene;

use App\Entity\Enterprise;
use App\Enum\SiretEvidence;

/**
 * One SIRET candidate with the reasons it is proposed - what the SIRET screen draws as a row.
 *
 * Nothing in here decides: the scores order the list, the evidence becomes badges, and a person
 * clicks « Associer » or does not (design/validated/siret-entreprises.md, R1-R2).
 */
final readonly class RankedCandidate
{
    public function __construct(
        public EstablishmentCandidate $establishment,
        /** Share of the searched name's words found in one of the establishment's names, 0 to 1. */
        public float $nameScore,
        /** 2 for the same street number, plus up to 2 street words in common - 0 to 4. */
        public int $streetScore,
        /** Street words in common on the best-matching line, the number aside. */
        public int $streetWords,
        public bool $samePlace,
        public bool $exactName,
        public bool $sameCompanyElsewhere = false,
        /** Another fiche already carrying this SIRET (R8) - never a reason to refuse, always said. */
        public ?Enterprise $linkedTo = null,
    ) {
    }

    /** Right name, right place - the first tier of the list. */
    public function isLikely(): bool
    {
        return $this->nameScore >= SiretCandidateFinder::NAME_MATCH && $this->samePlace;
    }

    /** @return list<SiretEvidence> */
    public function evidence(): array
    {
        $evidence = [];

        // The street is only evidence in the right town: « 4 rue Legouvé » exists in more than one.
        if ($this->samePlace && $this->streetScore >= 3) {
            $evidence[] = SiretEvidence::SameAddress;
        } elseif ($this->samePlace && 2 === $this->streetScore && $this->streetWords >= 1) {
            $evidence[] = SiretEvidence::SameStreet;
        } elseif ($this->samePlace) {
            $evidence[] = SiretEvidence::SamePostalCode;
        }

        if ($this->sameCompanyElsewhere) {
            $evidence[] = SiretEvidence::SameCompanyElsewhere;
        }

        return $evidence;
    }

    public function with(bool $sameCompanyElsewhere, ?Enterprise $linkedTo): self
    {
        return new self(
            $this->establishment,
            $this->nameScore,
            $this->streetScore,
            $this->streetWords,
            $this->samePlace,
            $this->exactName,
            $sameCompanyElsewhere,
            $linkedTo,
        );
    }

    public function withNameScore(float $nameScore): self
    {
        return new self(
            $this->establishment,
            $nameScore,
            $this->streetScore,
            $this->streetWords,
            $this->samePlace,
            $this->exactName,
            $this->sameCompanyElsewhere,
            $this->linkedTo,
        );
    }
}
