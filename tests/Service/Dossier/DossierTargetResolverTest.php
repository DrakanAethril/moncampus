<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Service\Dossier\DossierTargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * Who a dossier is actually asked of.
 *
 * The rule that needs pinning is the narrowing: a formation target carrying options reaches only the
 * students holding one of them, and **an empty option set means the whole class** - not « nobody »,
 * which is the reading a naive `IN ()` would produce and the one that would empty every dossier
 * written before options existed.
 *
 * It is resolved at read time, never frozen: a student who joins the class, or who picks the option
 * up in November, is a cible from that day. That is deliberately not the SurveyTarget rule, and the
 * difference is that a class list which lies about who is in the class is worse than one that moves.
 */
class DossierTargetResolverTest extends TestCase
{
    private User $lea;
    private User $hugo;
    private Program $program;
    private Option $slam;
    private Option $sisr;

    protected function setUp(): void
    {
        $this->lea = $this->student(1, 'Léa Durand');
        $this->hugo = $this->student(2, 'Hugo Moreau');
        $this->slam = $this->option(10, 'SLAM');
        $this->sisr = $this->option(11, 'SISR');

        $this->program = $this->createStub(Program::class);
        $this->program->method('getDisplayShortName')->willReturn('SIO-2');
        $this->program->method('getStudents')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$this->lea, $this->hugo]));
    }

    private function student(int $id, string $name): User
    {
        [$first, $last] = explode(' ', $name, 2);
        $user = new User(strtolower($first));
        $user->setFirstname($first);
        $user->setLastname($last);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function option(int $id, string $name): Option
    {
        $option = $this->createStub(Option::class);
        $option->method('getId')->willReturn($id);
        $option->method('getName')->willReturn($name);

        return $option;
    }

    private function resolver(array $studentsForOptions = [], array $optionsPerStudent = []): DossierTargetResolver
    {
        $studentOptions = $this->createStub(ProgramStudentOptionRepository::class);
        $studentOptions->method('findStudentsForProgramAndOptions')->willReturn($studentsForOptions);
        $studentOptions->method('findOptionsForStudent')->willReturnCallback(
            static fn (Program $program, User $student): array => $optionsPerStudent[$student->getId()] ?? [],
        );

        return new DossierTargetResolver($this->createStub(ProgramRepository::class), $studentOptions);
    }

    private function dossier(Option ...$options): Dossier
    {
        $dossier = new Dossier();
        $target = $dossier->addTargetProgram($this->program);

        foreach ($options as $option) {
            $target->addOption($option);
        }

        return $dossier;
    }

    public function testAnUnnarrowedTargetIsTheWholeClass(): void
    {
        $cibles = $this->resolver()->resolve($this->dossier());

        self::assertSame([$this->hugo, $this->lea], array_column($cibles, 'student'));
        self::assertSame(['SIO-2', 'SIO-2'], array_column($cibles, 'className'));
    }

    public function testANarrowedTargetReachesOnlyTheStudentsCarryingTheOption(): void
    {
        $cibles = $this->resolver(studentsForOptions: [$this->lea])->resolve($this->dossier($this->slam));

        self::assertSame([$this->lea], array_column($cibles, 'student'));
        // The cible still reads as their class: which part of SIO-2 was asked is the dossier's
        // business, not theirs.
        self::assertSame(['SIO-2'], array_column($cibles, 'className'));
    }

    public function testANamedStudentIsACibleWhateverTheNarrowingSays(): void
    {
        $dossier = $this->dossier($this->slam);
        $dossier->addTargetStudent($this->hugo);

        $cibles = $this->resolver(studentsForOptions: [$this->lea])->resolve($dossier);

        self::assertSame([$this->hugo, $this->lea], array_column($cibles, 'student'));
    }

    public function testMembershipOfANarrowedTargetIsTheStudentsOwnOptions(): void
    {
        $resolver = $this->resolver(optionsPerStudent: [1 => [$this->slam], 2 => [$this->sisr]]);
        $dossier = $this->dossier($this->slam);

        self::assertTrue($resolver->isTarget($dossier, $this->lea));
        self::assertFalse($resolver->isTarget($dossier, $this->hugo), 'SISR is not SLAM');
    }

    public function testNamingSeveralOptionsIsAUnionAndNotAnIntersection(): void
    {
        $resolver = $this->resolver(optionsPerStudent: [1 => [$this->slam], 2 => [$this->sisr]]);
        $dossier = $this->dossier($this->slam, $this->sisr);

        self::assertTrue($resolver->isTarget($dossier, $this->lea));
        self::assertTrue($resolver->isTarget($dossier, $this->hugo));
    }

    public function testAnUnnarrowedTargetAsksNothingOfTheStudentsOptions(): void
    {
        // No option carried at all, and it changes nothing: the whole class is the whole class.
        self::assertTrue($this->resolver()->isTarget($this->dossier(), $this->hugo));
    }

    public function testTheLabelNamesTheOptionsTheTargetIsNarrowedTo(): void
    {
        self::assertSame(['SIO-2'], $this->resolver()->programLabels($this->dossier()));
        self::assertSame(['SIO-2 (SLAM)'], $this->resolver()->programLabels($this->dossier($this->slam)));
        self::assertSame(['SIO-2 (SLAM, SISR)'], $this->resolver()->programLabels($this->dossier($this->slam, $this->sisr)));
    }

    public function testAFormationIsNamedOnceHoweverOftenItIsAdded(): void
    {
        $dossier = $this->dossier($this->slam);

        // Ticking the class again must hand back the row that already carries its options rather
        // than starting a second, empty one - which would silently widen the target.
        $again = $dossier->addTargetProgram($this->program);

        self::assertCount(1, $dossier->getTargetPrograms());
        self::assertTrue($again->getOptions()->contains($this->slam));
    }
}
