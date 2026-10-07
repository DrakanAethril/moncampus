<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfVisa;
use App\Entity\ProgramEcfSettings;
use App\Entity\SkillGroup;
use App\Enum\EcfActivityState;

/**
 * One alternance's ECF booklet as the follow-up card and the overview screen read it - built once
 * per screen by EcfBookletOverview. $settings holds what the formation says whatever the option
 * (organisme, lieu), $title what the certification of the student's option says (EcfTitle).
 */
final readonly class EcfOverview
{
    /**
     * @param list<array{type: EcfActivityType, activity: EcfActivity|null, state: EcfActivityState, lastVisa: EcfVisa|null}> $rows
     * @param list<SkillGroup>                                                                                                $excluded
     * @param list<EcfActivity>                                                                                               $orphans
     * @param list<EcfVisa>                                                                                                   $synthesisVisas
     */
    public function __construct(
        public EcfBooklet $booklet,
        public ProgramEcfSettings $settings,
        public EcfTitle $title,
        public array $rows,
        public array $excluded,
        public array $orphans,
        public bool $synthesisOpen,
        public array $synthesisVisas,
    ) {
    }

    public function isStarted(): bool
    {
        return null !== $this->booklet->getId();
    }

    public function signedCount(): int
    {
        return \count(array_filter($this->rows, static fn (array $row): bool => \in_array($row['state'], [EcfActivityState::Satisfied, EcfActivityState::NotSatisfied], true)));
    }
}
