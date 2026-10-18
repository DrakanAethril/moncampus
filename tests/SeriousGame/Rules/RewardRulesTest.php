<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Rules;

use App\Enum\SeriousGameFinish;
use App\SeriousGame\Rules\RewardRules;
use PHPUnit\Framework\TestCase;

/**
 * The collection pieces of L'Observatoire (design/validated/serious-games.md §8, « Récompenses »):
 * the Astrolabe in brass once every star is lit, silver at 10/12 on the Couronne, gold at 12/12; the
 * Étoile pin with it; a card per complete constellation, and the Couronne's once it was played to
 * the end.
 */
class RewardRulesTest extends TestCase
{
    private const array TROPHY = ['code' => 'astrolabe', 'silver_at' => 10, 'gold_at' => 12];
    private const array PIN = ['code' => 'pin-etoile'];

    public function testNothingBeforeAChapterIsComplete(): void
    {
        self::assertSame([], RewardRules::earned('observatoire', ['compas' => [6, 7]], null, false, self::TROPHY, self::PIN, 'couronne'));
    }

    public function testACompleteChapterGivesItsCard(): void
    {
        self::assertSame(['card-observatoire-compas' => null], RewardRules::earned('observatoire', ['compas' => [7, 7], 'sablier' => [5, 6]], null, false, self::TROPHY, self::PIN, 'couronne'));
    }

    public function testEveryStarLitGivesTheBrassTrophyAndThePin(): void
    {
        $earned = RewardRules::earned('observatoire', ['compas' => [7, 7], 'des' => [6, 6]], null, false, self::TROPHY, self::PIN, 'couronne');

        self::assertSame(SeriousGameFinish::Brass, $earned['astrolabe']);
        self::assertArrayHasKey('pin-etoile', $earned);
        self::assertArrayNotHasKey('card-observatoire-couronne', $earned);
    }

    public function testTheFinalTestRaisesTheFinish(): void
    {
        $chapters = ['compas' => [7, 7]];

        self::assertSame(SeriousGameFinish::Brass, RewardRules::earned('observatoire', $chapters, 9, true, self::TROPHY, self::PIN, 'couronne')['astrolabe']);
        self::assertSame(SeriousGameFinish::Silver, RewardRules::earned('observatoire', $chapters, 10, true, self::TROPHY, self::PIN, 'couronne')['astrolabe']);
        self::assertSame(SeriousGameFinish::Gold, RewardRules::earned('observatoire', $chapters, 12, true, self::TROPHY, self::PIN, 'couronne')['astrolabe']);
    }

    public function testTheCouronnesCardComesOncePlayedToTheEnd(): void
    {
        self::assertArrayHasKey('card-observatoire-couronne', RewardRules::earned('observatoire', ['compas' => [7, 7]], 3, true, self::TROPHY, self::PIN, 'couronne'));
    }
}
