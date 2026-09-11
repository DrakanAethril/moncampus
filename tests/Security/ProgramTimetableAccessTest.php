<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\VisibilityLevel;
use App\Security\FeatureAccess;
use App\Security\ProgramTimetableAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The three conditions of "may this person see this formation's timetable" are ANDed, and the
 * point of the test is the direction that went wrong on screen: lighting the `timetable` feature
 * for students never overrides a formation that reserved its own to the administration.
 *
 * The tiers are those of App\Enum\VisibilityLevel - an ordered hierarchy, so a staff member also
 * reads what is opened to teachers, and « Masqué » is read by nobody at all.
 */
class ProgramTimetableAccessTest extends TestCase
{
    /** @param list<string> $roles */
    private function access(array $roles, bool $featureEnabled = true): ProgramTimetableAccess
    {
        $user = $this->createStub(User::class);
        $user->method('getRoles')->willReturn($roles);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $featureAccess = $this->createStub(FeatureAccess::class);
        $featureAccess->method('isEnabled')->willReturnCallback(
            static fn (Feature $feature): bool => Feature::Timetable !== $feature || $featureEnabled,
        );

        return new ProgramTimetableAccess($security, $featureAccess);
    }

    // Stubbed rather than built: Program's constructor asks for a Cohort and a SchoolYear, and
    // neither says anything about who reads a timetable.
    private function program(VisibilityLevel $tier, bool $managed = true): Program
    {
        $program = $this->createStub(Program::class);
        $program->method('getTimetableVisibility')->willReturn($tier);
        $program->method('isTimetableManagementEnabled')->willReturn($managed);

        return $program;
    }

    public function testStudentReadsAFormationOpenToEveryone(): void
    {
        $this->assertTrue($this->access(['ROLE_STUDENT'])->isVisible($this->program(VisibilityLevel::Everyone)));
    }

    public function testFeatureBeingLitDoesNotOverrideTheFormationsOwnTier(): void
    {
        $access = $this->access(['ROLE_STUDENT'], featureEnabled: true);

        $this->assertFalse($access->isVisible($this->program(VisibilityLevel::AdminOnly)));
        $this->assertFalse($access->isVisible($this->program(VisibilityLevel::StaffAdmin)));
        $this->assertFalse($access->isVisible($this->program(VisibilityLevel::TeachersOnly)));
    }

    public function testTheFormationsOwnTierDoesNotOverrideAnExtinguishedFeature(): void
    {
        $access = $this->access(['ROLE_STUDENT'], featureEnabled: false);

        $this->assertFalse($access->isVisible($this->program(VisibilityLevel::Everyone)));
    }

    public function testAFormationThatDoesNotManageItsTimetableHereShowsNoneAtAll(): void
    {
        $access = $this->access(['ROLE_ADMIN']);

        $this->assertFalse($access->isVisible($this->program(VisibilityLevel::Everyone, managed: false)));
    }

    public function testTiersAreAHierarchyRatherThanExclusiveAudiences(): void
    {
        $this->assertTrue($this->access(['ROLE_STAFF'])->isVisible($this->program(VisibilityLevel::TeachersOnly)));
        $this->assertTrue($this->access(['ROLE_ADMIN'])->isVisible($this->program(VisibilityLevel::StaffAdmin)));
        $this->assertFalse($this->access(['ROLE_TEACHER'])->isVisible($this->program(VisibilityLevel::StaffAdmin)));
    }

    public function testHiddenIsReadByNobody(): void
    {
        $this->assertFalse($this->access(['ROLE_ADMIN'])->isVisible($this->program(VisibilityLevel::Hidden)));
    }

    public function testFilterProgramsKeepsOnlyWhatTheReaderMaySee(): void
    {
        $open = $this->program(VisibilityLevel::Everyone);
        $closed = $this->program(VisibilityLevel::AdminOnly);

        $this->assertSame([$open], $this->access(['ROLE_STUDENT'])->filterPrograms([$open, $closed]));
    }

    public function testFilterSessionsDropsTheSessionsOfAClosedFormation(): void
    {
        $open = $this->session($this->program(VisibilityLevel::Everyone));
        $closed = $this->session($this->program(VisibilityLevel::AdminOnly));

        $this->assertSame([$open], $this->access(['ROLE_STUDENT'])->filterSessions([$open, $closed]));
    }

    /**
     * The tiers handed to a query are the same answer read the other way round: whatever
     * visibleTiers() returns is exactly what isVisible() admits, or the SQL filter and the
     * in-memory one would disagree about the same formation.
     */
    public function testVisibleTiersMatchesWhatIsVisibleAdmits(): void
    {
        foreach ([['ROLE_STUDENT'], ['ROLE_TEACHER'], ['ROLE_STAFF'], ['ROLE_ADMIN']] as $roles) {
            $access = $this->access($roles);
            foreach (VisibilityLevel::cases() as $tier) {
                $this->assertSame(
                    $access->isVisible($this->program($tier)),
                    \in_array($tier, $access->visibleTiers(), true),
                    sprintf('%s / %s', implode('+', $roles), $tier->value),
                );
            }
        }
    }

    private function session(Program $program): LessonSession
    {
        $session = $this->createStub(LessonSession::class);
        $session->method('getProgram')->willReturn($program);

        return $session;
    }
}
