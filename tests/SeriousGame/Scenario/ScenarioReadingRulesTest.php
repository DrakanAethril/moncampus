<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Scenario;

use App\SeriousGame\Scenario\ScenarioReadingRules;
use App\SeriousGame\Scenario\ScenarioStepKind;
use PHPUnit\Framework\TestCase;

/**
 * What the teacher's reading of a scenario says of a student (design/validated/serious-games-neko-con.md
 * §5), on primitives: « Bloqués » - a ticket in progress with two refused deliveries and nothing
 * since -, « Beaucoup d'aide », and per course the share of tickets resolved without help.
 */
class ScenarioReadingRulesTest extends TestCase
{
    public function testATicketIsBlockedAfterTwoRefusedDeliveriesAndNothingSince(): void
    {
        $refused = ['kind' => ScenarioStepKind::Fix, 'ok' => false, 'help' => false];
        $accepted = ['kind' => ScenarioStepKind::Fix, 'ok' => true, 'help' => false];
        $guide = ['kind' => ScenarioStepKind::Line, 'ok' => false, 'help' => true];

        self::assertFalse(ScenarioReadingRules::blocked([$refused]));
        self::assertTrue(ScenarioReadingRules::blocked([$refused, $refused]));
        self::assertTrue(ScenarioReadingRules::blocked([$refused, $refused, $refused]));
        self::assertFalse(ScenarioReadingRules::blocked([$refused, $refused, $guide]), 'asking Inès is something since');
        self::assertFalse(ScenarioReadingRules::blocked([$refused, $refused, $accepted]));
        self::assertFalse(ScenarioReadingRules::blocked([$refused, ['kind' => ScenarioStepKind::Explain, 'ok' => false, 'help' => false]]));
    }

    public function testMuchHelpIsHelpOnHalfTheTicketsOpenedAndAtLeastTwo(): void
    {
        self::assertFalse(ScenarioReadingRules::muchHelp(0, 0));
        self::assertFalse(ScenarioReadingRules::muchHelp(1, 1), 'one ticket helped says little');
        self::assertTrue(ScenarioReadingRules::muchHelp(4, 2));
        self::assertFalse(ScenarioReadingRules::muchHelp(5, 2));
        self::assertTrue(ScenarioReadingRules::muchHelp(2, 2));
    }

    public function testTheShareWithoutHelpIsReadOnClosedTickets(): void
    {
        self::assertNull(ScenarioReadingRules::shareWithoutHelp([]));
        self::assertSame(0.5, ScenarioReadingRules::shareWithoutHelp([0, 2, 0, 1]));
        self::assertSame(1.0, ScenarioReadingRules::shareWithoutHelp([0]));
    }
}
