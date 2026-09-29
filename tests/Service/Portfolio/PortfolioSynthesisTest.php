<?php

declare(strict_types=1);

namespace App\Tests\Service\Portfolio;

use App\Enum\PortfolioSetting;
use App\Service\Portfolio\PortfolioSectionResolver;
use App\Service\Portfolio\PortfolioSynthesis;
use App\Service\Portfolio\SynthesisTable;
use PHPUnit\Framework\TestCase;

final class PortfolioSynthesisTest extends TestCase
{
    use PortfolioFixtures;

    public function testOnlyARetainedClaimOnAValidatedAchievementTicksACell(): void
    {
        $referential = $this->referential();
        $portfolio = $this->portfolio($referential);
        $glpi = $this->achievement($portfolio, 'GLPI', PortfolioSetting::Training, '2026-01-12', '2026-02-06', [0, 1, 4], [0, 4]);
        $this->achievement($portfolio, 'Zabbix', PortfolioSetting::Training, '2026-03-02', '2026-03-27', [0, 3], [], false);

        $table = PortfolioSynthesis::compose($portfolio, false, [], new PortfolioSectionResolver(), 'MARTIN Léa', 'Beaupeyrat', [], 2027);

        self::assertCount(1, $table->sections[1], 'a réalisation waiting for its decision is not exported');
        $cells = $table->sections[1][0]['cells'];
        $columns = $table->columns;
        self::assertSame(SynthesisTable::RETAINED, $cells[(int) $columns[0]->getId()]);
        self::assertNull($cells[(int) $columns[1]->getId()], 'claimed but not retained');
        self::assertSame(SynthesisTable::RETAINED, $cells[(int) $columns[4]->getId()]);
        self::assertSame('12/01/26 au 06/02/26', $table->sections[1][0]['period']);
        self::assertSame($glpi, $table->sections[1][0]['achievement']);
        self::assertSame(2, $table->coveredCount());
        self::assertFalse($table->isComplete());
    }

    public function testThePendingViewMarksWaitingClaimsAndSkipsDrafts(): void
    {
        $referential = $this->referential();
        $portfolio = $this->portfolio($referential);
        $this->achievement($portfolio, 'Zabbix', PortfolioSetting::Training, '2026-03-02', '2026-03-27', [0, 3], [], false);
        $draft = new \App\Entity\PortfolioAchievement($portfolio);
        $draft->setTitle('Brouillon');

        $table = PortfolioSynthesis::compose($portfolio, true, [], new PortfolioSectionResolver(), 'X', null, [], null);

        self::assertCount(1, $table->sections[1]);
        self::assertSame(SynthesisTable::PENDING, $table->sections[1][0]['cells'][(int) $table->columns[3]->getId()]);
        self::assertSame(0, $table->coverage[(int) $table->columns[3]->getId()], 'pending never counts as covered');
    }

    public function testBloc2ClaimsNeverReachTheE5Columns(): void
    {
        $referential = $this->referential();
        $portfolio = $this->portfolio($referential);
        $this->achievement($portfolio, 'Portail captif', PortfolioSetting::Workplace, '2026-05-19', '2026-06-27', [100, 101, 5], [100, 101, 5]);

        $table = PortfolioSynthesis::compose($portfolio, false, [], new PortfolioSectionResolver(), 'X', null, [], null);

        self::assertCount(6, $table->columns);
        self::assertCount(6, $table->sections[2][0]['cells']);
        self::assertSame(1, $table->coveredCount());
    }

    public function testTheWholeBlocMobilisedIsComplete(): void
    {
        $referential = $this->referential();
        $portfolio = $this->portfolio($referential);
        $this->achievement($portfolio, 'A', PortfolioSetting::Training, '2026-01-12', '2026-02-06', [0, 1, 2], [0, 1, 2]);
        $this->achievement($portfolio, 'B', PortfolioSetting::Workplace, '2026-05-19', '2026-06-27', [3, 4, 5], [3, 4, 5]);

        $table = PortfolioSynthesis::compose($portfolio, false, [], new PortfolioSectionResolver(), 'X', null, [], null);

        self::assertTrue($table->isComplete());
        self::assertSame([], $table->missing());
    }
}
