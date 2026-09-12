<?php

declare(strict_types=1);

namespace App\Tests\Service\Accommodation;

use App\Entity\Accommodation;
use App\Service\Accommodation\AccommodationProfile;
use PHPUnit\Framework\TestCase;

/**
 * How several aménagements held at once come to a single answer.
 *
 * **The largest extra time wins; it is never a sum.** Two accommodations each granting a third more
 * grant a third more, not two thirds - an accommodation states a need, and holding two of them does
 * not double it. The rule lives here rather than at each reader, which is the whole reason this
 * object exists.
 */
class AccommodationProfileTest extends TestCase
{
    public function testTheLargestExtraTimeWinsRatherThanTheSum(): void
    {
        $profile = AccommodationProfile::fromAccommodations([
            $this->accommodation('Tiers-temps', '33.33'),
            $this->accommodation('Mi-temps', '50.00'),
        ]);

        self::assertSame(50.0, $profile->quizExtraTimePercent);
        self::assertSame(45, $profile->applyToSeconds(30));
    }

    public function testAnAccommodationSilentOnTimeSaysNothingRatherThanZero(): void
    {
        // The row carries no extra time (it will carry some other criterion one day). It must not
        // drag the answer down to zero: what the other row grants stands.
        $profile = AccommodationProfile::fromAccommodations([
            $this->accommodation('Secrétaire', null),
            $this->accommodation('Tiers-temps', '33.33'),
        ]);

        self::assertSame(33.33, $profile->quizExtraTimePercent);
        self::assertTrue($profile->hasQuizExtraTime());
    }

    public function testHoldingOnlyTimelessAccommodationsGrantsNoExtraTime(): void
    {
        $profile = AccommodationProfile::fromAccommodations([$this->accommodation('Secrétaire', null)]);

        self::assertFalse($profile->hasQuizExtraTime());
        self::assertSame(30, $profile->applyToSeconds(30));
        self::assertNull($profile->quizExtraTimeLabel());
        // Nothing to freeze on a copy either - null is what says « no accommodation was in play ».
        self::assertNull($profile->quizExtraTimeForStorage());
    }

    public function testHoldingNoneChangesNothing(): void
    {
        $profile = AccommodationProfile::none();

        self::assertSame(30, $profile->applyToSeconds(30));
        self::assertNull($profile->applyToSeconds(null));
        self::assertNull($profile->applyToMinutesAsSeconds(null));
    }

    public function testTheWholeQuizBudgetIsCountedInSeconds(): void
    {
        $profile = AccommodationProfile::fromAccommodations([$this->accommodation('Tiers-temps', '33.33')]);

        // 25 min = 1 500 s, a third more is 1 999.95 s -> 2 000 s. Counted in minutes it would have
        // been 33,3325 min, and whichever way that got rounded the seconds would be wrong.
        self::assertSame(2000, $profile->applyToMinutesAsSeconds(25));
    }

    public function testTheFrozenValueKeepsTheTwoDecimalsTheColumnHolds(): void
    {
        $profile = AccommodationProfile::fromAccommodations([$this->accommodation('Tiers-temps', '33.33')]);

        self::assertSame('33.33', $profile->quizExtraTimeForStorage());
        self::assertSame('33,33 %', $profile->quizExtraTimeLabel());
    }

    private function accommodation(string $name, ?string $percent): Accommodation
    {
        return (new Accommodation($name))->setQuizExtraTimePercent($percent);
    }
}
