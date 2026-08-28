<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\User;
use App\Enum\GameTrack;
use App\Service\Game\GameTrackResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A formation is not a filière (App\Service\Game\GameTrackResolver).
 *
 * BTS SIO holds SLAM and SISR side by side in one class, and which of the two a student belongs to
 * is their **option**. The game's univers was stamped on the Program until 2026-08-27, which made
 * that class impossible to describe: one value cannot answer for two filières. This test is what
 * stops it going back.
 *
 * The Program keeps the value as a **fallback**, and that half is pinned too: a formation whose
 * whole class is one filière - Comptabilité, Management commercial - splits into no option at all
 * and has nothing else to say so with.
 */
class GameTrackResolutionTest extends FunctionalTestCase
{
    public function testTwoStudentsOfOneClassPlayInTheirOwnFilieres(): void
    {
        $slamStudent = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'track.slam');
        $sisrStudent = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'track.sisr');
        $program = $this->createProgram([$slamStudent, $sisrStudent]);

        $slam = $this->option($program, 'SLAM', GameTrack::Slam);
        $sisr = $this->option($program, 'SISR', GameTrack::Sisr);
        $this->enrol($program, $slamStudent, $slam);
        $this->enrol($program, $sisrStudent, $sisr);

        $resolver = $this->resolver();

        self::assertSame(GameTrack::Slam, $resolver->forStudent($slamStudent, $program));
        self::assertSame(GameTrack::Sisr, $resolver->forStudent($sisrStudent, $program));
        self::assertSame([GameTrack::Slam, GameTrack::Sisr], $resolver->forProgram($program), 'the class plays in both');
    }

    public function testAnOptionThatIsNotAFiliereDecidesNothing(): void
    {
        // Nearly every option is one of these - a group, a bilingual track, a mini-entreprise.
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'track.group');
        $program = $this->createProgram([$student]);
        $program->setGameTrack(GameTrack::Cg);

        $this->enrol($program, $student, $this->option($program, 'GR1', null));

        self::assertSame(GameTrack::Cg, $this->resolver()->forStudent($student, $program));
    }

    public function testAClassThatSplitsIntoNoOptionFallsBackOnTheFormation(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'track.whole');
        $program = $this->createProgram([$student]);
        $program->setGameTrack(GameTrack::Mco);
        $this->entityManager()->flush();

        self::assertSame(GameTrack::Mco, $this->resolver()->forStudent($student, $program));
        self::assertSame([GameTrack::Mco], $this->resolver()->forProgram($program));
    }

    public function testAFormationWithNothingSetAnywhereResolvesToNothing(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'track.none');
        $program = $this->createProgram([$student]);

        // A legitimate state: generic level wording, and no pseudonym at all.
        self::assertNull($this->resolver()->forStudent($student, $program));
        self::assertSame([], $this->resolver()->forProgram($program));
        self::assertSame([], $this->resolver()->tracksForStudent($student, $program));
    }

    public function testAStudentWithNoOptionYetPlaysInEveryFiliereTheirClassHolds(): void
    {
        // The ordinary state of a first term: the class splits into SLAM and SISR, and this student
        // is in neither yet. Answering them with no filière cost them both their level wording and
        // their pseudonym, so they play in both until their option says which.
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'track.unplaced');
        $program = $this->createProgram([$student]);

        $this->option($program, 'SLAM', GameTrack::Slam);
        $this->option($program, 'SISR', GameTrack::Sisr);

        self::assertSame([GameTrack::Slam, GameTrack::Sisr], $this->resolver()->tracksForStudent($student, $program));
        self::assertSame(GameTrack::Slam, $this->resolver()->forStudent($student, $program), 'a screen with one slot takes the first');
    }

    public function testAnOptionThatNamesAFiliereStillAnswersAlone(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'track.placed');
        $program = $this->createProgram([$student]);

        $this->option($program, 'SLAM', GameTrack::Slam);
        $this->enrol($program, $student, $this->option($program, 'SISR', GameTrack::Sisr));

        self::assertSame([GameTrack::Sisr], $this->resolver()->tracksForStudent($student, $program));
    }

    private function option(Program $program, string $shortName, ?GameTrack $track): Option
    {
        $option = new Option($shortName, $shortName, '#1B6BA8');
        $option->setGameTrack($track);
        $option->setCreatedBy($this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'track.author.'.mb_strtolower($shortName)));
        $program->addOption($option);

        $this->entityManager()->persist($option);
        $this->entityManager()->flush();

        return $option;
    }

    private function enrol(Program $program, User $student, Option $option): void
    {
        $this->entityManager()->persist(new ProgramStudentOption($program, $student, $option));
        $this->entityManager()->flush();
    }

    private function resolver(): GameTrackResolver
    {
        return static::getContainer()->get(GameTrackResolver::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
