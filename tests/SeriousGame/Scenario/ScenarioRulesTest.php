<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Scenario;

use App\SeriousGame\Scenario\ScenarioInputRefused;
use App\SeriousGame\Scenario\ScenarioRules;
use App\SeriousGame\Scenario\ScenarioStepKind;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the « scénario » mechanic (design/validated/serious-games-neko-con.md §2, §3.2), on
 * primitives: the graph and the board of four, the stars of a ticket, the correction of what the
 * server corrects, the pieces of the password and the end. Nothing depends on time.
 */
class ScenarioRulesTest extends TestCase
{
    public function testTheBoardOpensTicketsInScenarioOrderUpToItsCapacity(): void
    {
        $scenario = ScenarioFixture::scenario();

        self::assertSame(['T1', 'T2'], ScenarioRules::refill($scenario, [], []));
        self::assertSame([], ScenarioRules::refill($scenario, ['T1', 'T2'], []), 'the board is full');
    }

    public function testATicketOpensOnceEveryTicketItFollowsIsClosed(): void
    {
        $scenario = ScenarioFixture::scenario();

        self::assertSame(['T3'], ScenarioRules::refill($scenario, ['T1', 'T2'], ['T1']));
        self::assertSame([], ScenarioRules::refill($scenario, ['T1', 'T2', 'T3'], ['T1', 'T2']), 'T4 still waits for T3');
        self::assertSame(['T4'], ScenarioRules::refill($scenario, ['T1', 'T2', 'T3'], ['T1', 'T2', 'T3']));
    }

    public function testTheCurrentActIsTheLowestActOfAnOpenTicket(): void
    {
        $scenario = ScenarioFixture::scenario();

        self::assertSame(1, ScenarioRules::currentAct($scenario, [], []));
        self::assertSame(1, ScenarioRules::currentAct($scenario, ['T1', 'T2'], ['T2']));
        self::assertSame(2, ScenarioRules::currentAct($scenario, ['T1', 'T2', 'T3', 'T4'], ['T1', 'T2', 'T3']));
        self::assertSame(2, ScenarioRules::currentAct($scenario, ['T1', 'T2', 'T3', 'T4'], ['T1', 'T2', 'T3', 'T4']), 'finished: the last act');
    }

    public function testTheGameEndsWhenEveryTicketIsClosed(): void
    {
        $scenario = ScenarioFixture::scenario();

        self::assertFalse(ScenarioRules::finished($scenario, ['T1', 'T2', 'T3']));
        self::assertTrue(ScenarioRules::finished($scenario, ['T4', 'T3', 'T2', 'T1']));
    }

    public function testStarsCountErrorsAndHelp(): void
    {
        self::assertSame(3, ScenarioRules::stars(0, 0));
        self::assertSame(2, ScenarioRules::stars(1, 0));
        self::assertSame(2, ScenarioRules::stars(0, 1));
        self::assertSame(2, ScenarioRules::stars(1, 1));
        self::assertSame(2, ScenarioRules::stars(0, 2));
        self::assertSame(1, ScenarioRules::stars(2, 1));
        self::assertSame(1, ScenarioRules::stars(0, 3));
    }

    public function testAnErrorMadeDuringTheHelpDoesNotCountNorDoesAWrongPassword(): void
    {
        $errors = ScenarioRules::errors([
            ['kind' => ScenarioStepKind::Fix, 'ok' => false, 'help' => false],
            ['kind' => ScenarioStepKind::Fix, 'ok' => true, 'help' => false],
            ['kind' => ScenarioStepKind::Line, 'ok' => false, 'help' => true],
            ['kind' => ScenarioStepKind::Choice, 'ok' => false, 'help' => true],
            ['kind' => ScenarioStepKind::Explain, 'ok' => false, 'help' => false],
            ['kind' => ScenarioStepKind::Password, 'ok' => false, 'help' => false],
        ]);

        self::assertSame(2, $errors);
    }

    public function testAnExplanationIsCorrectedWithTheMisconceptionOfAWrongOne(): void
    {
        $step = ScenarioFixture::scenario()->ticket('T1')?->step('expliquer');
        self::assertNotNull($step);

        $wrong = ScenarioRules::grade($step, 0, 'Neko');
        self::assertFalse($wrong->ok);
        self::assertSame('police', $wrong->misconception);
        self::assertSame(['who' => 'ines', 'expr' => 'sourcil', 'text' => 'Non.'], $wrong->reaction);
        self::assertSame('0', $wrong->stored);

        $right = ScenarioRules::grade($step, '1', 'Neko');
        self::assertTrue($right->ok);
        self::assertNull($right->misconception);
        self::assertSame('Merci !', $right->reaction['text'] ?? null);

        $plain = ScenarioRules::grade($step, 2, 'Neko');
        self::assertFalse($plain->ok);
        self::assertSame('lang', $plain->misconception);
        self::assertSame('ines', $plain->reaction['who'] ?? null, 'a wrong option with no reaction gets Inès’ default one');
    }

    public function testAnOptionThatDoesNotExistIsRefused(): void
    {
        $step = ScenarioFixture::scenario()->ticket('T1')?->step('expliquer');
        self::assertNotNull($step);

        $this->expectException(ScenarioInputRefused::class);
        ScenarioRules::grade($step, 7, 'Neko');
    }

    public function testALineIsRightWhenItHoldsTheExpectedText(): void
    {
        $step = ScenarioFixture::scenario()->ticket('T1')?->guideStep('localiser');
        self::assertNotNull($step);

        self::assertTrue(ScenarioRules::grade($step, '  <head>  ', 'Neko')->ok);
        $wrong = ScenarioRules::grade($step, '  <body class="x">', 'Neko');
        self::assertFalse($wrong->ok);
        self::assertNull($wrong->misconception);
        self::assertSame('<body class="x">', $wrong->stored);
        self::assertNotNull($wrong->reaction);
    }

    public function testADeliveryIsJudgedByTheBrowserAndKeepsOnlyTheFailedCriteria(): void
    {
        $step = ScenarioFixture::scenario()->ticket('T1')?->step('corriger');
        self::assertNotNull($step);

        $refused = ScenarioRules::grade($step, ['passed' => false, 'failed' => ['Le slogan a ses accents', 'La date est lisible']], 'Neko');
        self::assertFalse($refused->ok);
        self::assertSame('Le slogan a ses accents · La date est lisible', $refused->stored);

        $accepted = ScenarioRules::grade($step, ['passed' => true, 'failed' => []], 'Neko');
        self::assertTrue($accepted->ok);
        self::assertNull($accepted->stored);

        self::assertFalse(ScenarioRules::grade($step, ['passed' => true, 'failed' => ['Une case']], 'Neko')->ok, 'a delivery with a failed criterion is not passed');
    }

    public function testThePasswordIsCheckedAndNeverStored(): void
    {
        $step = ScenarioFixture::scenario()->ticket('T4')?->step('mdp');
        self::assertNotNull($step);

        $right = ScenarioRules::grade($step, 'Neko', 'Neko');
        self::assertTrue($right->ok);
        self::assertNull($right->stored);

        $wrong = ScenarioRules::grade($step, 'neko', 'Neko');
        self::assertFalse($wrong->ok);
        self::assertNull($wrong->stored);
        self::assertNotNull($wrong->reaction);
    }

    public function testThePiecesOfThePasswordAndTheCluesComeFromClosedTickets(): void
    {
        $scenario = ScenarioFixture::scenario();

        self::assertSame([null, null], ScenarioRules::fragments($scenario, ['T2']));
        self::assertSame(['Ne', null], ScenarioRules::fragments($scenario, ['T1', 'T2']));
        self::assertSame([], ScenarioRules::clues($scenario, ['T1']));
        self::assertSame(['Un champ caché.'], ScenarioRules::clues($scenario, ['T1', 'T3']));
    }
}
