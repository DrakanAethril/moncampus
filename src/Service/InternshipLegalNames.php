<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Option;
use App\Entity\Program;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;

/**
 * The « Dénomination » printed for an alternant - on the Livret de l'alternant's cover and on the
 * ECF booklet's. A student with exactly one Option gets that Option's override if set
 * (InternshipOptionLegalName); otherwise - and always for a student with zero or several Options -
 * the program-wide denomination (InternshipProgramInfo::$legalName), itself falling back to
 * Program::$name. A document only ever shows one name.
 */
class InternshipLegalNames
{
    public function __construct(
        private readonly InternshipProgramInfoRepository $programInfoRepository,
        private readonly InternshipOptionLegalNameRepository $optionLegalNameRepository,
    ) {
    }

    /** @param list<Option> $studentOptions */
    public function forStudentOptions(Program $program, array $studentOptions): string
    {
        $defaultName = $this->programInfoRepository->findOneByProgram($program)?->getLegalName() ?: $program->getName();

        if (1 !== \count($studentOptions)) {
            return $defaultName;
        }

        return $this->optionLegalNameRepository->findOneForProgramAndOption($program, $studentOptions[0])?->getLegalName() ?? $defaultName;
    }
}
