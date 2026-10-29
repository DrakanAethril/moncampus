<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Rules;

use App\Enum\SeriousGameFinish;
use App\SeriousGame\Rules\RewardRules;
use PHPUnit\Framework\TestCase;

/**
 * The collection pieces of Panique à la Neko-Con (design/validated/serious-games-neko-con.md §4):
 * a card per act whose tickets were all closed; the Badge Staff in brass once the site is online,
 * silver at 26 tickets with three stars, gold at 35; the Neko pin with it. No card for the final
 * ticket - it is a ticket of the last act.
 */
class ScenarioRewardRulesTest extends TestCase
{
    private const array TROPHY = ['code' => 'badge-staff', 'silver_at' => 26, 'gold_at' => 35];
    private const array PIN = ['code' => 'pin-neko'];

    public function testNothingBeforeAnActIsDone(): void
    {
        self::assertSame([], RewardRules::scenarioEarned('neko-con', ['acte1' => [4, 5]], false, 0, self::TROPHY, self::PIN));
    }

    public function testADoneActGivesItsCard(): void
    {
        self::assertSame(
            ['card-neko-con-acte1' => null],
            RewardRules::scenarioEarned('neko-con', ['acte1' => [5, 5], 'acte2' => [1, 5]], false, 5, self::TROPHY, self::PIN),
        );
    }

    public function testTheSiteOnlineGivesTheBrassBadgeAndThePin(): void
    {
        $earned = RewardRules::scenarioEarned('neko-con', ['acte9' => [1, 1]], true, 25, self::TROPHY, self::PIN);

        self::assertSame(SeriousGameFinish::Brass, $earned['badge-staff']);
        self::assertArrayHasKey('pin-neko', $earned);
        self::assertArrayHasKey('card-neko-con-acte9', $earned);
    }

    public function testThreeStarTicketsRaiseTheFinish(): void
    {
        self::assertSame(SeriousGameFinish::Silver, RewardRules::scenarioEarned('neko-con', [], true, 26, self::TROPHY, self::PIN)['badge-staff']);
        self::assertSame(SeriousGameFinish::Silver, RewardRules::scenarioEarned('neko-con', [], true, 34, self::TROPHY, self::PIN)['badge-staff']);
        self::assertSame(SeriousGameFinish::Gold, RewardRules::scenarioEarned('neko-con', [], true, 35, self::TROPHY, self::PIN)['badge-staff']);
    }

    public function testStarsAloneGiveNoBadgeBeforeTheSiteIsOnline(): void
    {
        self::assertArrayNotHasKey('badge-staff', RewardRules::scenarioEarned('neko-con', [], false, 38, self::TROPHY, self::PIN));
    }
}
