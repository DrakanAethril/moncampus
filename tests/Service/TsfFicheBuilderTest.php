<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\Track;
use App\Enum\CertificationKind;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramCertificationRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillRepository;
use App\Service\TsfFicheBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The builder composes the two header lines of every fiche out of three different places, and those
 * compositions are the only real rules it has - the rest is copying fields across.
 */
class TsfFicheBuilderTest extends TestCase
{
    public function testComposesTheUnitLineFromOptionCodeAndLabel(): void
    {
        $program = $this->program();
        $group = new SkillGroup('Développer une application sécurisée', $program);
        $group->setCode('CCP 1')->addOption(new Option("Concepteur Développeur d'Applications", 'CDA', '#000'));

        $fiches = $this->build($program, [[$group, [$this->skill('C.1', 'Installer', $group)]]]);

        self::assertSame('CDA - CCP 1 : Développer une application sécurisée', $fiches[0]['unit']);
    }

    /** A cross-cutting block has neither an option nor a code and prints as its label alone. */
    public function testFallsBackToTheLabelAloneWhenNeitherOptionNorCode(): void
    {
        $program = $this->program();
        $group = new SkillGroup('Compétences transverses', $program);

        $fiches = $this->build($program, [[$group, [$this->skill('C.12', 'Communication', $group)]]]);

        self::assertSame('Compétences transverses', $fiches[0]['unit']);
    }

    public function testKeepsTheCodeWhenTheBlockHasNoOption(): void
    {
        $program = $this->program();
        $group = new SkillGroup('Un bloc', $program);
        $group->setCode('CCP 2');

        $fiches = $this->build($program, [[$group, [$this->skill('C.5', 'Une compétence', $group)]]]);

        self::assertSame('CCP 2 : Un bloc', $fiches[0]['unit']);
    }

    /**
     * The certification printed on a fiche is the one its own option prepares. The source document
     * puts the AIS title on the CDA fiches too; that is its mistake, not a behaviour to copy.
     */
    public function testPrintsTheCertificationOfTheBlocksOwnOption(): void
    {
        $program = $this->program();
        $cda = new Option('Concepteur Développeur', 'CDA', '#000');
        $ais = new Option('Administrateur Infra', 'AIS', '#111');

        $cdaGroup = new SkillGroup('Bloc CDA', $program);
        $cdaGroup->addOption($cda);
        $aisGroup = new SkillGroup('Bloc AIS', $program);
        $aisGroup->addOption($ais);

        $fiches = $this->build(
            $program,
            [
                [$cdaGroup, [$this->skill('C.1', 'Une', $cdaGroup)]],
                [$aisGroup, [$this->skill('C.1', 'Deux', $aisGroup)]],
            ],
            [
                'CDA' => $this->certification($program, $cda, "Concepteur Développeur d'Applications"),
                'AIS' => $this->certification($program, $ais, "Administrateur d'Infrastructures Sécurisées"),
            ],
        );

        self::assertSame("TP - Concepteur Développeur d'Applications", $fiches[0]['certification']);
        self::assertSame("TP - Administrateur d'Infrastructures Sécurisées", $fiches[1]['certification']);
    }

    public function testLeavesTheCertificationEmptyWhenTheProgramDeclaresNone(): void
    {
        $program = $this->program();
        $group = new SkillGroup('Un bloc', $program);

        $fiches = $this->build($program, [[$group, [$this->skill('C.1', 'Une', $group)]]]);

        self::assertSame('', $fiches[0]['certification']);
    }

    /** The fiche prints whole hours; a half-hour keeps its half. */
    public function testTrimsTheDecimalsOfAWholeVolume(): void
    {
        $program = $this->program();
        $group = new SkillGroup('Un bloc', $program);

        $skills = [
            $this->skill('C.1', 'Une', $group)->setVolumeHours('30.00'),
            $this->skill('C.2', 'Deux', $group)->setVolumeHours('1.50'),
            $this->skill('C.3', 'Trois', $group),
        ];

        $fiches = $this->build($program, [[$group, $skills]]);

        self::assertSame('30', $fiches[0]['volumeHours']);
        self::assertSame('1,5', $fiches[1]['volumeHours']);
        self::assertSame('', $fiches[2]['volumeHours']);
    }

    public function testKeepsTheReferentialOrderAcrossBlocks(): void
    {
        $program = $this->program();
        $first = new SkillGroup('Premier', $program);
        $second = new SkillGroup('Second', $program);

        $fiches = $this->build($program, [
            [$first, [$this->skill('C.1', 'Une', $first), $this->skill('C.2', 'Deux', $first)]],
            [$second, [$this->skill('C.3', 'Trois', $second)]],
        ]);

        self::assertSame(['C.1', 'C.2', 'C.3'], array_column($fiches, 'code'));
    }

    private function program(): Program
    {
        $cohort = new Cohort('Promo', new Track('Filière', new Section('Section')));
        $schoolYear = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'));

        return new Program('Bac+3 Informatique', 'Bac+3 Info', $cohort, $schoolYear);
    }

    private function skill(string $code, string $label, SkillGroup $group): Skill
    {
        return (new Skill($label, $group))->setCode($code);
    }

    private function certification(Program $program, Option $option, string $label): ProgramCertification
    {
        return (new ProgramCertification($program, $option, $label))->setKind(CertificationKind::TitrePro);
    }

    /**
     * @param list<array{0: SkillGroup, 1: list<Skill>}> $pairs
     * @param array<string, ProgramCertification>        $certifications keyed by option short name
     *
     * @return list<array<string, string>>
     */
    private function build(Program $program, array $pairs, array $certifications = []): array
    {
        $groups = [];
        $skillsByGroup = new \SplObjectStorage();
        foreach ($pairs as [$group, $skills]) {
            $groups[] = $group;
            $skillsByGroup[$group] = $skills;
        }

        $groupRepository = $this->createStub(SkillGroupRepository::class);
        $groupRepository->method('findAllOrderedForProgram')->willReturn($groups);

        $skillRepository = $this->createStub(SkillRepository::class);
        $skillRepository->method('findAllOrderedForSkillGroup')->willReturnCallback(
            /** @return list<Skill> */
            static function (SkillGroup $group) use ($skillsByGroup): array {
                /** @var list<Skill> $found */
                $found = $skillsByGroup[$group] ?? [];

                return $found;
            },
        );

        $certificationRepository = $this->createStub(ProgramCertificationRepository::class);
        $certificationRepository->method('findForOption')->willReturnCallback(
            static fn (Program $p, ?Option $option): ?ProgramCertification => null === $option
                ? null
                : ($certifications[$option->getShortName()] ?? null),
        );

        $builder = new TsfFicheBuilder(
            $groupRepository,
            $skillRepository,
            $certificationRepository,
            $this->createStub(InternshipFormationCenterRepository::class),
        );

        return $builder->build($program);
    }
}
