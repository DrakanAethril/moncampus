<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuizTournamentRoundRefusal;
use App\Service\QuizTournament\QuizTournamentRoundOpening;
use App\Service\QuizTournament\QuizTournamentRoundRules;
use PHPUnit\Framework\TestCase;

/**
 * A round in class (design/validated/quiz-tournament.md §8), on primitives: who is expected, what a
 * teacher may open, who may join, who is present, and who enters the pairing when the teacher
 * closes it.
 */
class QuizTournamentRoundRulesTest extends TestCase
{
    public function testTheWholeClassIsExpectedWithoutARestriction(): void
    {
        self::assertSame([1, 2, 3], QuizTournamentRoundRules::expected([1, 2, 3], [1, 2, 3, 9], null));
    }

    public function testOnlyTheEligibleStudentsOfTheClassAreExpected(): void
    {
        // 2 is enrolled but no longer eligible (a test account, a lost targeting); 9 is eligible
        // through another class of the tournament - not this round's.
        self::assertSame([1, 3], QuizTournamentRoundRules::expected([1, 2, 3], [1, 3, 9], null));
    }

    public function testARestrictionNarrowsTheClassAndIsReadOnTheDay(): void
    {
        // The group was saved with 4 and 5; 4 has left the class since, 5 is no longer eligible.
        self::assertSame([1], QuizTournamentRoundRules::expected([1, 2, 3, 5], [1, 2, 3], [1, 4, 5]));
        self::assertSame([], QuizTournamentRoundRules::expected([1, 2], [1, 2], []));
    }

    public function testTheExpectedKeepTheClassOrderAndNoDuplicate(): void
    {
        self::assertSame([3, 1], QuizTournamentRoundRules::expected([3, 1, 3], [1, 3], [1, 3, 3]));
    }

    public function testATeacherOpensARoundForTheWholeClass(): void
    {
        self::assertNull(QuizTournamentRoundRules::openRefusal($this->opening()));
    }

    public function testOnlyOneRoundIsOpenPerClassAtATime(): void
    {
        self::assertSame(QuizTournamentRoundRefusal::AlreadyOpen, QuizTournamentRoundRules::openRefusal($this->opening(classHasOpenRound: true)));
    }

    public function testOpeningNeedsTheRightToOpenForThatClass(): void
    {
        self::assertSame(QuizTournamentRoundRefusal::NotAllowed, QuizTournamentRoundRules::openRefusal($this->opening(canOpen: false)));
        self::assertSame(QuizTournamentRoundRefusal::NotAllowed, QuizTournamentRoundRules::openRefusal($this->opening(canOpen: false, classHasOpenRound: true)));
    }

    public function testAnOptionMustBeOneOfTheClass(): void
    {
        self::assertNull(QuizTournamentRoundRules::openRefusal($this->opening(optionId: 7, classOptionIds: [6, 7])));
        self::assertSame(QuizTournamentRoundRefusal::OptionNotInClass, QuizTournamentRoundRules::openRefusal($this->opening(optionId: 8, classOptionIds: [6, 7])));
    }

    public function testAGroupMustBeOfALotOfThatClassTheTeacherMayRead(): void
    {
        self::assertNull(QuizTournamentRoundRules::openRefusal($this->opening(batchId: 4, batchProgramId: 10, batchVisible: true, groupIndex: 1, groupCount: 2)));
        // A lot of another class, a lot nobody shared with them, a group that is not in the lot.
        self::assertSame(QuizTournamentRoundRefusal::GroupNotAvailable, QuizTournamentRoundRules::openRefusal($this->opening(batchId: 4, batchProgramId: 11, batchVisible: true, groupIndex: 0, groupCount: 2)));
        self::assertSame(QuizTournamentRoundRefusal::GroupNotAvailable, QuizTournamentRoundRules::openRefusal($this->opening(batchId: 4, batchProgramId: 10, batchVisible: false, groupIndex: 0, groupCount: 2)));
        self::assertSame(QuizTournamentRoundRefusal::GroupNotAvailable, QuizTournamentRoundRules::openRefusal($this->opening(batchId: 4, batchProgramId: 10, batchVisible: true, groupIndex: 2, groupCount: 2)));
        self::assertSame(QuizTournamentRoundRefusal::GroupNotAvailable, QuizTournamentRoundRules::openRefusal($this->opening(batchId: 4, batchProgramId: 10, batchVisible: true, groupIndex: null, groupCount: 2)));
        self::assertSame(QuizTournamentRoundRefusal::GroupNotAvailable, QuizTournamentRoundRules::openRefusal($this->opening(batchId: 4, batchProgramId: 10, batchVisible: true, groupIndex: -1, groupCount: 2)));
    }

    public function testAnOptionAndAGroupAtOnceIsRefused(): void
    {
        self::assertSame(QuizTournamentRoundRefusal::TwoRestrictions, QuizTournamentRoundRules::openRefusal($this->opening(optionId: 7, classOptionIds: [7], batchId: 4, batchProgramId: 10, batchVisible: true, groupIndex: 0, groupCount: 1)));
    }

    public function testAnExpectedStudentJoinsAnOpenRound(): void
    {
        self::assertNull(QuizTournamentRoundRules::joinRefusal(true, true, true));
        self::assertSame(QuizTournamentRoundRefusal::RoundClosed, QuizTournamentRoundRules::joinRefusal(false, true, true));
        self::assertSame(QuizTournamentRoundRefusal::CannotPlay, QuizTournamentRoundRules::joinRefusal(true, false, true));
        self::assertSame(QuizTournamentRoundRefusal::NotExpected, QuizTournamentRoundRules::joinRefusal(true, true, false));
    }

    public function testPresenceLastsAsLongAsThePageBeats(): void
    {
        $now = new \DateTimeImmutable('2026-11-04 10:00:00');

        self::assertFalse(QuizTournamentRoundRules::isPresent(null, $now));
        self::assertTrue(QuizTournamentRoundRules::isPresent($now->modify('-30 seconds'), $now));
        self::assertFalse(QuizTournamentRoundRules::isPresent($now->modify('-31 seconds'), $now));
    }

    public function testTheEntrantsAreTheParticipantsStillExpectedPresentAndFree(): void
    {
        $now = new \DateTimeImmutable('2026-11-04 10:00:00');
        $fresh = $now->modify('-5 seconds');

        $entrants = QuizTournamentRoundRules::entrants(
            [1, 2, 3, 4, 5, 6],
            [1, 2, 3, 4, 6],               // 5 left the class since they joined
            [1 => $fresh, 2 => $fresh, 3 => $now->modify('-2 minutes'), 4 => $fresh, 5 => $fresh, 6 => $fresh],
            [1, 2, 3, 5, 6],               // 4 can no longer play
            [6],                           // 6 is playing a duel elsewhere
            $now,
        );

        self::assertSame([1, 2], $entrants);
    }

    public function testClosingNeedsTwoEntrants(): void
    {
        self::assertNull(QuizTournamentRoundRules::closeRefusal(true, true, 2));
        self::assertSame(QuizTournamentRoundRefusal::TooFew, QuizTournamentRoundRules::closeRefusal(true, true, 1));
        self::assertSame(QuizTournamentRoundRefusal::RoundClosed, QuizTournamentRoundRules::closeRefusal(false, true, 6));
        self::assertSame(QuizTournamentRoundRefusal::NotAllowed, QuizTournamentRoundRules::closeRefusal(true, false, 6));
    }

    /** @param list<int> $classOptionIds */
    private function opening(
        bool $canOpen = true,
        bool $classHasOpenRound = false,
        ?int $optionId = null,
        array $classOptionIds = [],
        ?int $batchId = null,
        ?int $batchProgramId = null,
        bool $batchVisible = false,
        ?int $groupIndex = null,
        int $groupCount = 0,
    ): QuizTournamentRoundOpening {
        return new QuizTournamentRoundOpening(10, $canOpen, $classHasOpenRound, $optionId, $classOptionIds, $batchId, $batchProgramId, $batchVisible, $groupIndex, $groupCount);
    }
}
