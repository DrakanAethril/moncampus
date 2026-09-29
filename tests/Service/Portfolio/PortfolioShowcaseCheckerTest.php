<?php

declare(strict_types=1);

namespace App\Tests\Service\Portfolio;

use App\Entity\PortfolioShowcase;
use App\Enum\PortfolioSetting;
use App\Service\Portfolio\PortfolioShowcaseChecker;
use App\Service\Portfolio\SynthesisTable;
use PHPUnit\Framework\TestCase;

final class PortfolioShowcaseCheckerTest extends TestCase
{
    use PortfolioFixtures;

    public function testTwoValidatedFichesCoveringTheBlocAreComplete(): void
    {
        $referential = $this->referential();
        $portfolio = $this->portfolio($referential);
        $a = $this->achievement($portfolio, 'Zabbix', PortfolioSetting::Training, '2026-03-02', '2026-03-27', [101, 102], [101, 102]);
        $b = $this->achievement($portfolio, 'CDI', PortfolioSetting::Training, '2026-10-07', '2026-12-12', [100, 101], [100, 101]);
        $one = new PortfolioShowcase($portfolio, 1, $a);
        $two = new PortfolioShowcase($portfolio, 2, $b);
        $one->submit();
        $one->markValidated();
        $two->submit();

        $checker = new PortfolioShowcaseChecker();
        $block = $referential->getBlocks()->get(1);

        $pending = $checker->check($block, [$one, $two]);
        self::assertFalse($pending['complete'], 'fiche 2 is not validated yet');
        self::assertSame(SynthesisTable::PENDING, $pending['rows'][0]['byNumber'][2]);
        self::assertFalse($pending['rows'][0]['covered']);

        $two->markValidated();
        $done = $checker->check($block, [$one, $two]);
        self::assertTrue($done['complete']);
        self::assertSame(SynthesisTable::RETAINED, $done['rows'][1]['byNumber'][1]);
    }

    public function testAFicheCanOnlyBeNumberedOneOrTwo(): void
    {
        $referential = $this->referential();
        $portfolio = $this->portfolio($referential);
        $a = $this->achievement($portfolio, 'Zabbix', PortfolioSetting::Training, '2026-03-02', '2026-03-27', [101], [101]);

        $this->expectException(\InvalidArgumentException::class);
        new PortfolioShowcase($portfolio, 3, $a);
    }

    public function testAValidatedFicheReopensWhenItsAchievementChanges(): void
    {
        $referential = $this->referential();
        $portfolio = $this->portfolio($referential);
        $a = $this->achievement($portfolio, 'Zabbix', PortfolioSetting::Training, '2026-03-02', '2026-03-27', [101], [101]);
        $fiche = new PortfolioShowcase($portfolio, 1, $a);
        $fiche->submit();
        $fiche->markValidated();

        self::assertTrue($a->touch(), 'a validated réalisation that changes reopens');
        $fiche->followAchievement();

        self::assertSame('submitted', $fiche->getState()->value);
        self::assertSame('claimed', $a->getClaims()->first()->getState()->value, 'its claims are asked again');
    }
}
