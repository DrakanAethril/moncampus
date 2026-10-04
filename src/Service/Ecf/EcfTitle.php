<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;

/**
 * The titre an ECF booklet prints on its cover and in its footer, read from what the platform
 * already knows rather than typed in the ECF tab: the label is the Livret de l'alternant's
 * « Dénomination », level, code titre and millésime are the certification's (UFA > Formations >
 * « Dénomination »), the sigle is the student's option's short name, else the formation's.
 *
 * Code titre + millésime is also the key a booklet is found by (EcfBookletLocator): without both
 * there is no booklet to show.
 */
final readonly class EcfTitle
{
    public function __construct(
        public string $label,
        public string $sigle,
        public ?string $level,
        public string $code,
        public string $millesime,
    ) {
    }

    /** $option is the student's one option, null when they have none or several. */
    public static function of(string $denomination, ?ProgramCertification $certification, ?Option $option, Program $program): self
    {
        $level = $certification?->getLevel();

        return new self(
            $denomination,
            $option?->getShortName() ?? $program->getShortName(),
            null !== $level ? (string) $level : null,
            trim((string) $certification?->getTitleCode()),
            trim((string) $certification?->getMillesime()),
        );
    }

    public function isComplete(): bool
    {
        return '' !== $this->code && '' !== $this->millesime;
    }
}
