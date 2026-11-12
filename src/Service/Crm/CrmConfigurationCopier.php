<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\Program;
use App\Entity\SchoolYear;

/**
 * « Copier des réglages » and « Importer une configuration »: what a formation is set to in the CRM
 * copied onto others - from one year to another, each formation onto the same class of the other
 * year (matched by its Formatool cohort), or from one formation onto several. The dossiers never
 * move.
 */
final readonly class CrmConfigurationCopier
{
    public const string PART_FOLLOWED = 'followed';
    public const string PART_MODALITY = 'modality';
    public const string PART_OPTION = 'option';
    public const string PART_AMOUNTS = 'amounts';
    public const string PART_PIECES = 'pieces';

    public function __construct(private CrmSettings $settings)
    {
    }

    /** @return list<string> */
    public static function parts(): array
    {
        return [self::PART_FOLLOWED, self::PART_MODALITY, self::PART_OPTION, self::PART_AMOUNTS, self::PART_PIECES];
    }

    /**
     * Each formation of the source year with its counterpart in the target year, and the ones that
     * have none.
     *
     * @return array{pairs: list<array{from: Program, to: Program}>, missing: list<Program>}
     */
    public function yearPlan(SchoolYear $from, SchoolYear $to): array
    {
        $targets = [];
        foreach ($this->settings->programsOf($to) as $program) {
            $cohort = $program->getCohort()?->getId();
            if (null !== $cohort) {
                $targets[$cohort] ??= $program;
            }
        }

        $pairs = [];
        $missing = [];
        foreach ($this->settings->programsOf($from) as $program) {
            $target = $targets[(int) $program->getCohort()?->getId()] ?? null;
            if (null === $target) {
                if (null !== $this->settings->settingsOf($program)) {
                    $missing[] = $program;
                }
                continue;
            }
            $pairs[] = ['from' => $program, 'to' => $target];
        }

        return ['pairs' => $pairs, 'missing' => $missing];
    }

    /**
     * @param list<array{from: Program, to: Program}> $pairs
     * @param list<string>                            $parts
     */
    public function copy(array $pairs, array $parts): int
    {
        $count = 0;
        foreach ($pairs as ['from' => $from, 'to' => $to]) {
            if ($from === $to) {
                continue;
            }
            $source = $this->settings->settingsOf($from);
            $target = $this->settings->settingsFor($to);

            if (\in_array(self::PART_FOLLOWED, $parts, true)) {
                $target->setFollowed(true === $source?->isFollowed());
            }
            if (\in_array(self::PART_MODALITY, $parts, true)) {
                $target->setDefaultModality($this->settings->defaultModality($from));
            }
            // Options are Formatool's: an option is carried over only where the target has one of
            // the same short name.
            $sameOptions = $this->sameOptions($from, $to);
            if (\in_array(self::PART_OPTION, $parts, true) && [] !== $sameOptions) {
                $tracked = [];
                foreach ($sameOptions as $fromId => $toId) {
                    if (true === $source?->tracksOption($fromId)) {
                        $tracked[] = $toId;
                    }
                }
                $target->setTrackedOptionIds($tracked);
            }
            if (\in_array(self::PART_AMOUNTS, $parts, true)) {
                $target->setDepositAmount($source?->getDepositAmount());
                $target->setNpecAmount($source?->getNpecAmount());
                $amounts = [];
                foreach ($sameOptions as $fromId => $toId) {
                    $own = ($source?->getOptionAmounts() ?? [])[$fromId] ?? null;
                    if (null !== $own) {
                        $amounts[$toId] = $own;
                    }
                }
                $target->setOptionAmounts($amounts);
            }
            if (\in_array(self::PART_PIECES, $parts, true)) {
                $target->setPieceConstraints($source?->getPieceConstraints() ?? []);
            }
            ++$count;
        }

        return $count;
    }

    /** @return array<int, int> source option id => target option id, matched by short name */
    private function sameOptions(Program $from, Program $to): array
    {
        $byName = [];
        foreach ($to->getOptions() as $option) {
            $byName[mb_strtolower($option->getShortName())] = (int) $option->getId();
        }
        $pairs = [];
        foreach ($from->getOptions() as $option) {
            $target = $byName[mb_strtolower($option->getShortName())] ?? null;
            if (null !== $target) {
                $pairs[(int) $option->getId()] = $target;
            }
        }

        return $pairs;
    }
}
