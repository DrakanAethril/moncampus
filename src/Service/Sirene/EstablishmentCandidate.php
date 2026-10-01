<?php

declare(strict_types=1);

namespace App\Service\Sirene;

/**
 * One establishment of the SIRENE register, as the Recherche d'entreprises API describes it - what
 * a person needs in front of them to say « yes, that is this employer ».
 *
 * The signs and the trade name are kept because they are how a company is known on the street:
 * « XEFI Limoges Sud » is the sign of an establishment whose legal name is DASAU.
 */
final readonly class EstablishmentCandidate
{
    /**
     * @param list<string> $signs the establishment's « enseignes »
     */
    public function __construct(
        public string $siret,
        public string $siren,
        public string $fullName,
        public ?string $legalName,
        public ?string $acronym,
        public array $signs,
        public ?string $tradeName,
        public string $address,
        public ?string $postalCode,
        public bool $open,
        public bool $headOffice,
        public ?\DateTimeImmutable $createdOn,
        public ?\DateTimeImmutable $closedOn,
        public ?float $latitude = null,
        public ?float $longitude = null,
        /** The establishment's own NAF code - may differ from its company's (CGI Limoges: 70.22Z). */
        public ?string $activityCode = null,
        /** INSEE headcount bracket of this establishment, `NN` or null when unknown. */
        public ?string $employeeBracket = null,
        public ?string $city = null,
    ) {
    }

    /**
     * Every name the establishment answers to - legal name, acronym, signs, trade name.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_filter(
            [$this->fullName, $this->legalName, $this->acronym, ...$this->signs, $this->tradeName],
            static fn (?string $name): bool => null !== $name && '' !== trim($name),
        ));
    }

    /**
     * The signs and trade name that differ from the legal name - what is worth showing beside it.
     *
     * @return list<string>
     */
    public function otherNames(): array
    {
        $shown = mb_strtoupper(trim($this->fullName));
        $others = [];
        foreach ([...$this->signs, $this->tradeName] as $name) {
            if (null === $name || '' === trim($name)) {
                continue;
            }
            $upper = mb_strtoupper(trim($name));
            if (!str_contains($shown, $upper) && !\in_array($upper, array_map('mb_strtoupper', $others), true)) {
                $others[] = trim($name);
            }
        }

        return $others;
    }
}
