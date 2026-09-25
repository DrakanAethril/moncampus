<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipTutorLink;
use App\Repository\ContractTypeRepository;
use App\Repository\ProgramContractModalityRepository;

/**
 * The modalités de contrat an alternant's booklet prints, as sections 5, 6, 7... of chapter I: the
 * text of their contract type written for their own formation (ProgramContractModality), failing
 * that the centre de formation's default for that contract type (ContractType), failing that
 * nothing at all - no section, no line in the sommaire.
 */
class BookletContractModalities
{
    /** Chapter I's fixed sections stop at 4. Équipe pédagogique. */
    public const int FIRST_SECTION = 5;

    public const string ANCHOR_PREFIX = 'section-i-';

    public function __construct(
        private readonly ContractTypeRepository $contractTypeRepository,
        private readonly ProgramContractModalityRepository $modalityRepository,
        private readonly BookletFreeTextLayout $layout,
    ) {
    }

    public function forTutorLink(InternshipTutorLink $tutorLink): ?BookletFreeText
    {
        $contractType = $this->contractTypeRepository->findOneByCode($tutorLink->getContractType());
        $program = $tutorLink->getProgram();
        if (null === $contractType || null === $program) {
            return null;
        }

        $override = $this->modalityRepository->findOneForProgramAndContractType($program, $contractType);

        return $this->layout->lay($override?->getModalitiesHtml() ?? $contractType->getDefaultModalitiesHtml(), self::FIRST_SECTION, self::ANCHOR_PREFIX);
    }
}
