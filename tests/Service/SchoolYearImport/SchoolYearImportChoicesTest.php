<?php

declare(strict_types=1);

namespace App\Tests\Service\SchoolYearImport;

use App\Enum\SchoolYearImportBlock as Block;
use App\Service\SchoolYearImport\SchoolYearImportChoices;
use PHPUnit\Framework\TestCase;

/**
 * The boxes of the import as the request sends them: unknown values dropped, and a box whose
 * dependency is unticked unticked too - whatever the request says.
 */
class SchoolYearImportChoicesTest extends TestCase
{
    public function testDefaultsTickEverythingButTheGameAndTheCrm(): void
    {
        $choices = SchoolYearImportChoices::defaults(3, [10, 11]);

        self::assertTrue($choices->has(Block::Team));
        self::assertTrue($choices->has(Block::Holders));
        self::assertTrue($choices->has(Block::PeriodCalendars));
        self::assertFalse($choices->has(Block::Game));
        self::assertFalse($choices->has(Block::Crm));
        self::assertSame([10, 11], $choices->programIds);
    }

    public function testUnknownBlocksAndJunkIdsAreDropped(): void
    {
        $choices = SchoolYearImportChoices::fromInput(3, ['blocks' => ['topics', 'nope', ['x']], 'programs' => ['12', 'abc', '0', '-4', '12']]);

        self::assertSame([Block::Topics], $choices->blocks);
        self::assertSame([12], $choices->programIds);
    }

    public function testAParameterThatIsNotAListReadsAsNothing(): void
    {
        $choices = SchoolYearImportChoices::fromInput(3, ['blocks' => 'team', 'programs' => '4']);

        self::assertSame([], $choices->blocks);
        self::assertSame([], $choices->programIds);
    }

    public function testTeamSubBoxesNeedTheTeam(): void
    {
        $choices = SchoolYearImportChoices::fromInput(3, ['blocks' => ['referents', 'teacher_options', 'holders', 'topics']]);

        self::assertSame([Block::Topics], $choices->blocks);
    }

    public function testHoldersNeedTopicsOrSkills(): void
    {
        $withSkills = SchoolYearImportChoices::fromInput(3, ['blocks' => ['team', 'holders', 'skills']]);
        $alone = SchoolYearImportChoices::fromInput(3, ['blocks' => ['team', 'holders']]);

        self::assertTrue($withSkills->has(Block::Holders));
        self::assertFalse($alone->has(Block::Holders));
    }

    public function testCalendarLinksNeedOneCalendarBlock(): void
    {
        self::assertTrue(SchoolYearImportChoices::fromInput(3, ['blocks' => ['calendar_links', 'evaluation_calendars']])->has(Block::CalendarLinks));
        self::assertFalse(SchoolYearImportChoices::fromInput(3, ['blocks' => ['calendar_links']])->has(Block::CalendarLinks));
    }

    public function testPortfolioValidatorsNeedTheTeamAndThePortfolio(): void
    {
        self::assertFalse(SchoolYearImportChoices::fromInput(3, ['blocks' => ['portfolio_validators', 'portfolio']])->has(Block::PortfolioValidators));
        self::assertFalse(SchoolYearImportChoices::fromInput(3, ['blocks' => ['portfolio_validators', 'team']])->has(Block::PortfolioValidators));
        self::assertTrue(SchoolYearImportChoices::fromInput(3, ['blocks' => ['portfolio_validators', 'team', 'portfolio']])->has(Block::PortfolioValidators));
    }

    public function testRoundTripThroughTheJournal(): void
    {
        $choices = SchoolYearImportChoices::fromInput(7, ['blocks' => ['skills', 'team'], 'programs' => ['5', '9']]);

        $again = SchoolYearImportChoices::fromArray($choices->toArray());

        self::assertSame(['source' => 7, 'blocks' => ['team', 'skills'], 'programs' => [5, 9]], $again->toArray());
    }
}
