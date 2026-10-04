<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\Cohort;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\SchoolYear;
use App\Service\Ecf\EcfTitle;
use PHPUnit\Framework\TestCase;

/**
 * The titre an ECF booklet prints is read, never typed in the ECF tab: the denomination of the
 * Livret de l'alternant, the level, code titre and millésime of the certification, the sigle from
 * the student's option. What is pinned: each source, the fallbacks, and that a missing code titre
 * or millésime makes the titre incomplete - it is the booklet's key.
 */
class EcfTitleTest extends TestCase
{
    private Program $program;

    protected function setUp(): void
    {
        $this->program = new Program('Bachelor informatique 2025-2026', 'B3 Info', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
    }

    private function certification(?Option $option, ?string $code, ?string $millesime, ?int $level = 6): ProgramCertification
    {
        return (new ProgramCertification($this->program, $option, 'Concepteur développeur d’applications'))
            ->setLevel($level)
            ->setTitleCode($code)
            ->setMillesime($millesime);
    }

    public function testReadsTheDenominationAndTheCertificationAndTheOptionShortName(): void
    {
        $option = new Option('Concepteur développeur d’applications', 'CDA', '#000');

        $title = EcfTitle::of('Titre professionnel CDA', $this->certification($option, ' TP-01281 ', ' 04 '), $option, $this->program);

        self::assertSame('Titre professionnel CDA', $title->label);
        self::assertSame('CDA', $title->sigle);
        self::assertSame('6', $title->level);
        self::assertSame('TP-01281', $title->code);
        self::assertSame('04', $title->millesime);
        self::assertTrue($title->isComplete());
    }

    public function testWithoutASingleOptionTheSigleIsTheFormationShortName(): void
    {
        $title = EcfTitle::of('Bachelor', $this->certification(null, 'TP-01281', '04', null), null, $this->program);

        self::assertSame('B3 Info', $title->sigle);
        self::assertNull($title->level);
    }

    public function testAMissingCodeTitreOrMillesimeLeavesTheTitleIncomplete(): void
    {
        self::assertFalse(EcfTitle::of('X', null, null, $this->program)->isComplete());
        self::assertFalse(EcfTitle::of('X', $this->certification(null, 'TP-01281', ' '), null, $this->program)->isComplete());
        self::assertFalse(EcfTitle::of('X', $this->certification(null, null, '04'), null, $this->program)->isComplete());
    }
}
