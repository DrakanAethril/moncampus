<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\Cohort;
use App\Entity\InternshipFormationCenter;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\ProgramEcfSettings;
use App\Entity\SchoolYear;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramCertificationRepository;
use App\Repository\ProgramEcfSettingsRepository;
use App\Service\Ecf\EcfActivityTypes;
use App\Service\Ecf\EcfSettingsEditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The ECF part of « Dénomination ». What is pinned: the certifications are judged as the form
 * holds them - the very submit that types a code titre may switch the booklet on -, an option
 * whose own certification is blank answers with the formation's, and the two reasons that keep the
 * switch off.
 */
class EcfSettingsEditorTest extends TestCase
{
    private Program $program;

    protected function setUp(): void
    {
        $this->program = new Program('Bachelor informatique 2025-2026', 'B3 Info', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
    }

    private function editor(bool $hasActivityTypes = true, ?ProgramCertification $programWide = null, ?ProgramEcfSettings $saved = null, ?InternshipFormationCenter $center = null): EcfSettingsEditor
    {
        $settings = $this->createStub(ProgramEcfSettingsRepository::class);
        $settings->method('findOneByProgram')->willReturn($saved);
        $centers = $this->createStub(InternshipFormationCenterRepository::class);
        $centers->method('findSingleton')->willReturn($center);
        $certifications = $this->createStub(ProgramCertificationRepository::class);
        $certifications->method('findOneForProgramAndOption')->willReturn($programWide);
        $activityTypes = $this->createStub(EcfActivityTypes::class);
        $activityTypes->method('hasActivityTypes')->willReturn($hasActivityTypes);

        return new EcfSettingsEditor($settings, $centers, $certifications, $activityTypes, $this->createStub(EntityManagerInterface::class));
    }

    /** @return array{fieldName: string, option: Option|null, certification: ProgramCertification} */
    private function row(?Option $option, string $label, ?string $code, ?string $millesime): array
    {
        return [
            'fieldName' => 'certification_0',
            'option' => $option,
            'certification' => (new ProgramCertification($this->program, $option, $label))->setTitleCode($code)->setMillesime($millesime),
        ];
    }

    public function testAnOptionLacksATitleWhenItsCertificationLacksCodeTitreOrMillesime(): void
    {
        $cda = new Option('Concepteur développeur d’applications', 'CDA', '#000');
        $ais = new Option('Administrateur d’infrastructures sécurisées', 'AIS', '#000');

        $without = $this->editor()->optionsWithoutTitle($this->program, [
            $this->row($cda, 'Concepteur développeur d’applications', 'TP-01281', '04'),
            $this->row($ais, 'Administrateur d’infrastructures sécurisées', 'TP-01414', ' '),
        ]);

        self::assertSame([$ais], $without);
    }

    public function testAnOptionWithABlankCertificationAnswersWithTheFormationWideOne(): void
    {
        $cda = new Option('Concepteur développeur d’applications', 'CDA', '#000');
        $programWide = (new ProgramCertification($this->program, null, 'Bachelor'))->setTitleCode('TP-01281')->setMillesime('04');
        $rows = [$this->row($cda, ' ', 'TP-09999', '01')];

        self::assertSame([], $this->editor(programWide: $programWide)->optionsWithoutTitle($this->program, $rows));
        self::assertSame([$cda], $this->editor()->optionsWithoutTitle($this->program, $rows));
    }

    public function testAFormationWithoutOptionsIsJudgedOnItsOneCertification(): void
    {
        self::assertSame([null], $this->editor()->optionsWithoutTitle($this->program, [$this->row(null, 'Bachelor', null, '04')]));
        self::assertSame([], $this->editor()->optionsWithoutTitle($this->program, [$this->row(null, 'Bachelor', 'TP-01281', '04')]));
    }

    public function testTheSwitchIsRefusedWithoutActivityTypesOrWithoutAnyCompleteTitle(): void
    {
        $on = (new ProgramEcfSettings($this->program))->setEnabled(true);
        $complete = [$this->row(null, 'Bachelor', 'TP-01281', '04')];
        $incomplete = [$this->row(null, 'Bachelor', 'TP-01281', null)];

        self::assertSame([], $this->editor()->refusals($on, $complete));
        self::assertSame(['ecfSettingsRefusalNoGroupMessage'], $this->editor(hasActivityTypes: false)->refusals($on, $complete));
        self::assertSame(['ecfSettingsRefusalNoTitleMessage'], $this->editor()->refusals($on, $incomplete));
        self::assertSame(['ecfSettingsRefusalNoGroupMessage', 'ecfSettingsRefusalNoTitleMessage'], $this->editor(hasActivityTypes: false)->refusals($on, $incomplete));
    }

    public function testOneCompleteOptionAmongSeveralIsEnoughToSwitchOn(): void
    {
        $on = (new ProgramEcfSettings($this->program))->setEnabled(true);
        $rows = [
            $this->row(new Option('Concepteur développeur d’applications', 'CDA', '#000'), 'CDA', 'TP-01281', '04'),
            $this->row(new Option('Administrateur d’infrastructures sécurisées', 'AIS', '#000'), 'AIS', null, null),
        ];

        self::assertSame([], $this->editor()->refusals($on, $rows));
    }

    public function testAFormationThatDoesNotKeepTheBookletIsRefusedNothing(): void
    {
        $off = new ProgramEcfSettings($this->program);

        self::assertSame([], $this->editor(hasActivityTypes: false)->refusals($off, [$this->row(null, '', null, null)]));
    }

    public function testAFirstVisitProposesTheTrainingCentreAsOrganismeAndLieu(): void
    {
        $center = (new \ReflectionClass(InternshipFormationCenter::class))->newInstanceWithoutConstructor();
        $center->setCfaName('UFA Beaupeyrat')->setCompanyName('Institution Beaupeyrat')->setCity('Limoges');

        $proposed = $this->editor(center: $center)->settings($this->program);

        self::assertNull($proposed->getId());
        self::assertFalse($proposed->isEnabled());
        self::assertSame('UFA Beaupeyrat', $proposed->getOrganisation());
        self::assertSame('Institution Beaupeyrat Limoges', $proposed->getPlace());
    }

    public function testSavedSettingsAreNeverOverwrittenByTheProposal(): void
    {
        $saved = (new ProgramEcfSettings($this->program))->setOrganisation('Autre organisme');
        $center = (new \ReflectionClass(InternshipFormationCenter::class))->newInstanceWithoutConstructor();
        $center->setCfaName('UFA Beaupeyrat');

        self::assertSame($saved, $this->editor(saved: $saved, center: $center)->settings($this->program));
        self::assertSame('Autre organisme', $saved->getOrganisation());
    }
}
