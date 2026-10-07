<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;

/**
 * The titre an ECF booklet prints on its cover and in its footer, read from UFA > Formations >
 * « Dénomination »: the label is the Livret de l'alternant's denomination; level, code titre,
 * millésime and the dates of the arrêté are the certification's - the one of the student's option,
 * since two options of a formation prepare two titres; the sigle is that option's short name, else
 * the formation's.
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
        public ?\DateTimeImmutable $decreeDate = null,
        public ?\DateTimeImmutable $journalDate = null,
        public ?\DateTimeImmutable $effectiveDate = null,
        // The « Date de mise à jour » of the ministry's template footer.
        public ?\DateTimeImmutable $modelUpdatedDate = null,
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
            $certification?->getDecreeDate(),
            $certification?->getJournalDate(),
            $certification?->getEffectiveDate(),
            $certification?->getModelUpdatedDate(),
        );
    }

    public function isComplete(): bool
    {
        return '' !== $this->code && '' !== $this->millesime;
    }
}
