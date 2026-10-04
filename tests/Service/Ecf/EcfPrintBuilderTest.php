<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\Cohort;
use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfEvaluationRow;
use App\Entity\Program;
use App\Entity\ProgramEcfSettings;
use App\Entity\SchoolYear;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Enum\EcfPart;
use App\Repository\InternshipFormationCenterRepository;
use App\Service\Ecf\EcfActivityType;
use App\Service\Ecf\EcfMastery;
use App\Service\Ecf\EcfOverview;
use App\Service\Ecf\EcfPrintBuilder;
use App\Service\FileUploadService;
use PHPUnit\Framework\TestCase;

/**
 * What the printed booklet is made of: the main sheet's three « Compétences évaluées » cells take
 * one number each and the third the rest, the paragraphs are the description's lines, and a sheet
 * the formation no longer offers is printed only when it was signed.
 */
class EcfPrintBuilderTest extends TestCase
{
    public function testSpreadsCompetencesOverTheThreeCellsAndKeepsParagraphs(): void
    {
        $program = new Program('CDA 2', 'CDA2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $booklet = new EcfBooklet(new User('candidate'), 'TP-01281', '04');
        $activity = new EcfActivity($booklet, 'AT1');
        (new EcfEvaluationRow($activity, EcfPart::Main, 1))->setDescription("Docker\n\nGithub")->setCompetences([4, 1, 2, 9]);
        new EcfActivity($booklet, 'AT9');
        $type = new EcfActivityType(1, 'AT1', 'Développer', ['Environnement'], new SkillGroup('Développer', $program));
        $overview = new EcfOverview($booklet, (new ProgramEcfSettings($program))->setSigle('CDA'), [['type' => $type, 'activity' => $activity, 'state' => \App\Enum\EcfActivityState::InProgress, 'lastVisa' => null]], [], [$booklet->activityFor('AT9')], false, []);

        $builder = new EcfPrintBuilder(new EcfMastery(), $this->createStub(InternshipFormationCenterRepository::class), $this->createStub(FileUploadService::class));
        $data = $builder->build($overview);

        self::assertCount(1, $data['activities'], 'an unsigned orphan sheet is not printed');
        $row = $data['activities'][0]['rows'][0];
        self::assertSame(['1', '2', '4, 9'], $row['columns']);
        self::assertSame(['Docker', 'Github'], $row['paragraphs']);
        self::assertSame('CDA', $data['footer']['sigle']);
        self::assertSame(['ecf-cover', 'ecf-presentation', 'ecf-at-1', 'ecf-at-1-complementary', 'ecf-synthesis'], array_column(EcfPrintBuilder::outline($data['activities']), 'anchor'));
    }
}
