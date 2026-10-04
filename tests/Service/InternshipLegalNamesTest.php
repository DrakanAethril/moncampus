<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Service\InternshipLegalNames;
use PHPUnit\Framework\TestCase;

/**
 * The « Dénomination » a student's documents print - the Livret de l'alternant's cover and the ECF
 * booklet's. Pinned: a single option's override wins, zero or several options take the formation's
 * denomination, and an empty denomination falls back to the formation's name.
 */
class InternshipLegalNamesTest extends TestCase
{
    private Program $program;
    private Option $cda;

    protected function setUp(): void
    {
        $this->program = new Program('Bachelor informatique', 'B3', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $this->cda = new Option('Concepteur développeur', 'CDA', '#000');
    }

    private function names(?string $programLegalName, ?string $optionLegalName = null): InternshipLegalNames
    {
        $info = $this->createStub(InternshipProgramInfoRepository::class);
        $info->method('findOneByProgram')->willReturn((new InternshipProgramInfo($this->program))->setLegalName($programLegalName));
        $overrides = $this->createStub(InternshipOptionLegalNameRepository::class);
        $overrides->method('findOneForProgramAndOption')->willReturn(null === $optionLegalName ? null : new InternshipOptionLegalName($this->program, $this->cda, $optionLegalName));

        return new InternshipLegalNames($info, $overrides);
    }

    public function testASingleOptionTakesItsOwnDenomination(): void
    {
        self::assertSame('Titre CDA', $this->names('Bachelor', 'Titre CDA')->forStudentOptions($this->program, [$this->cda]));
        self::assertSame('Bachelor', $this->names('Bachelor')->forStudentOptions($this->program, [$this->cda]));
    }

    public function testZeroOrSeveralOptionsTakeTheFormationDenomination(): void
    {
        self::assertSame('Bachelor', $this->names('Bachelor', 'Titre CDA')->forStudentOptions($this->program, []));
        self::assertSame('Bachelor', $this->names('Bachelor', 'Titre CDA')->forStudentOptions($this->program, [$this->cda, new Option('Autre', 'AIS', '#000')]));
    }

    public function testWithoutDenominationTheFormationNameIsUsed(): void
    {
        self::assertSame('Bachelor informatique', $this->names(null)->forStudentOptions($this->program, []));
        self::assertSame('Bachelor informatique', $this->names('')->forStudentOptions($this->program, []));
    }
}
