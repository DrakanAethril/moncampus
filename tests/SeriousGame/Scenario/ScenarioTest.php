<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Scenario;

use App\SeriousGame\Scenario\Scenario;
use App\SeriousGame\Scenario\ScenarioStepKind;
use PHPUnit\Framework\TestCase;

/**
 * The reading of a manifest's `scenario` (design/validated/serious-games-neko-con.md §3.1): the
 * tickets with their defaults, their steps found by identifier, and what the game may be told of a
 * step - never its answer.
 */
class ScenarioTest extends TestCase
{
    public function testItReadsTheActsTheCapacityAndThePassword(): void
    {
        $scenario = ScenarioFixture::scenario();

        self::assertSame(3, $scenario->version());
        self::assertSame(2, $scenario->capacity());
        self::assertSame('Neko', $scenario->password());
        self::assertCount(2, $scenario->acts());
        self::assertSame(['n' => 2, 'title' => 'La fin', 'tools' => ['apercu', 'lecteur']], $scenario->acts()[1]);
        self::assertSame(['T1', 'T2', 'T3', 'T4'], $scenario->ticketIds());
    }

    public function testAGameRestartsOnlyOnceFinishedUnlessItsManifestSaysOtherwise(): void
    {
        self::assertFalse(ScenarioFixture::scenario()->restartAnytime(), 'the Neko-Con rule: after the certificate');
        self::assertTrue(Scenario::fromArray(['restart_anytime' => true])->restartAnytime());
        self::assertFalse(Scenario::fromArray(['restart_anytime' => 'yes'])->restartAnytime(), 'a boolean, nothing else');
    }

    public function testATicketTakesItsDefaults(): void
    {
        $ticket = ScenarioFixture::scenario()->ticket('T1');
        self::assertNotNull($ticket);

        self::assertSame([], $ticket->after);
        self::assertFalse($ticket->reassigns());
        self::assertSame(1, $ticket->duplicates);
        self::assertNull($ticket->from);
        self::assertFalse($ticket->final);
        self::assertSame(4, $ticket->maxHints(), 'two guided questions, then two written hints');
        self::assertSame(['index' => 1, 'value' => 'Ne', 'where' => 'En haut.'], $ticket->fragment);

        $reassigned = ScenarioFixture::scenario()->ticket('T2');
        self::assertNotNull($reassigned);
        self::assertTrue($reassigned->reassigns());
        self::assertSame('normale', $reassigned->priority);
        self::assertNull(ScenarioFixture::scenario()->ticket('T9'));
    }

    public function testAStepIsFoundByItsIdentifier(): void
    {
        $ticket = ScenarioFixture::scenario()->ticket('T4');
        self::assertNotNull($ticket);

        self::assertSame(0, $ticket->stepIndex('corriger'));
        self::assertSame(2, $ticket->stepIndex('mdp'));
        self::assertSame(0, $ticket->stepIndex(null), 'a ticket not started is at its first step');
        self::assertSame(0, $ticket->stepIndex('ancienne-etape'), 'a step a revision removed starts the ticket again');
        self::assertSame(3, $ticket->stepIndex(Scenario::ALL_STEPS_DONE));
        self::assertSame('expliquer', $ticket->stepAfter('corriger'));
        self::assertSame(Scenario::ALL_STEPS_DONE, $ticket->stepAfter('mdp'));
        self::assertSame(ScenarioStepKind::Password, $ticket->step('mdp')?->kind);
    }

    public function testTheGameIsToldAStepWithoutItsAnswer(): void
    {
        $ticket = ScenarioFixture::scenario()->ticket('T1');
        self::assertNotNull($ticket);

        self::assertSame(
            ['id' => 'expliquer', 'kind' => 'explain', 'prompt' => 'Pourquoi ?', 'options' => ['Parce que la police', 'Parce que l’encodage', 'Parce que lang'], 'expr' => 'neutre'],
            $ticket->step('expliquer')?->publicView(),
        );
        self::assertSame(
            ['id' => 'localiser', 'kind' => 'line', 'prompt' => 'Clique.', 'file' => 'index.html'],
            $ticket->guideStep('localiser')?->publicView(),
        );
    }

    public function testAMisconceptionIsNamedByTheDictionaryOrByItsShortestOption(): void
    {
        $scenario = ScenarioFixture::scenario();

        self::assertSame('La police manque d’accents', $scenario->misconceptionLabel('police'));
        self::assertSame('lang', $scenario->misconceptionLabel('lang'), 'the guided question’s short option');
        self::assertSame('inconnue', $scenario->misconceptionLabel('inconnue'));
    }

    public function testAMissingScenarioIsEmpty(): void
    {
        $scenario = Scenario::fromArray([]);

        self::assertSame([], $scenario->tickets());
        self::assertSame(4, $scenario->capacity());
        self::assertSame(1, $scenario->version());
    }
}
