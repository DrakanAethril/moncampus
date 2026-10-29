<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Scenario;

use App\SeriousGame\Scenario\ScenarioValidator;
use PHPUnit\Framework\TestCase;

/**
 * What the sync checks of a scenario before installing it - the server's half of the ticket bench
 * (design/validated/serious-games-neko-con.md §8, lot 2): the graph closes, every question has one
 * right answer, a line names a text, a repair is followed by its explanation, the password has its
 * pieces.
 */
class ScenarioValidatorTest extends TestCase
{
    public function testTheFixtureIsValid(): void
    {
        self::assertSame([], ScenarioValidator::problems(ScenarioFixture::data()));
    }

    public function testAnUnknownTicketInTheGraphIsAProblem(): void
    {
        $data = ScenarioFixture::data(['T3' => ['after' => ['T9']]]);

        self::assertContains('T3 : « after » inconnu T9', ScenarioValidator::problems($data));
    }

    public function testAQuestionNeedsExactlyOneRightAnswer(): void
    {
        $data = ScenarioFixture::data(['T2' => ['steps' => [['id' => 'trier', 'kind' => 'choice', 'prompt' => 'À toi ?', 'options' => [['text' => 'Non', 'ok' => true], ['text' => 'Oui', 'ok' => true]]]]]]);

        self::assertSame(['T2/trier : 2 bonnes réponses'], ScenarioValidator::problems($data));
    }

    public function testARepairIsFollowedByItsExplanation(): void
    {
        $data = ScenarioFixture::data(['T1' => ['steps' => [ScenarioFixture::explanation(), ['id' => 'corriger', 'kind' => 'fix', 'prompt' => 'Répare.']]]]);

        self::assertContains('T1 : la réparation doit venir en premier, suivie de l’explication', ScenarioValidator::problems($data));
    }

    public function testALineNamesTheTextOfTheRightLine(): void
    {
        $data = ScenarioFixture::data(['T1' => ['guide' => [
            ['id' => 'localiser', 'kind' => 'line', 'file' => 'index.html', 'prompt' => 'Clique.', 'answer' => []],
            ['id' => 'diagnostic', 'kind' => 'choice', 'prompt' => 'Quoi ?', 'options' => [['text' => 'meta', 'ok' => true], ['text' => 'lang', 'misconception' => 'lang']]],
        ]]]);

        self::assertSame(['T1/localiser : aucune ligne attendue'], ScenarioValidator::problems($data));
    }

    public function testTheGraphMustLetEveryTicketOpen(): void
    {
        $data = ScenarioFixture::data(['T1' => ['after' => ['T4']]]);

        self::assertContains('le graphe n’ouvre jamais T1, T3, T4', ScenarioValidator::problems($data));
    }

    public function testDuplicateTicketsAndAPasswordWithoutPiecesAreProblems(): void
    {
        $data = ScenarioFixture::data([], ['password' => [], 'tickets' => [['id' => 'T1', 'act' => 1, 'steps' => [['id' => 'trier', 'kind' => 'choice', 'prompt' => '?', 'options' => [['text' => 'Oui', 'ok' => true]]]]]]]);

        $problems = ScenarioValidator::problems($data);
        self::assertContains('T1 : ticket en double', $problems);
        self::assertContains('aucun morceau de mot de passe', $problems);
    }

    public function testALineOutsideTheHelpDesignatesAView(): void
    {
        $line = ['id' => 'designer', 'kind' => 'line', 'prompt' => 'Clique.', 'answer' => 'dport=51820'];
        $data = ScenarioFixture::data(['T2' => ['steps' => [$line]]]);
        self::assertSame(['T2/designer : une étape « ligne » hors de l’aide désigne une vue (« view »)'], ScenarioValidator::problems($data));

        $data = ScenarioFixture::data(['T2' => ['steps' => [$line + ['view' => 'conntrack']]]]);
        self::assertSame([], ScenarioValidator::problems($data));
    }

    public function testAnOrderPlacesEveryItemOnce(): void
    {
        $order = ['id' => 'chronologie', 'kind' => 'order', 'prompt' => 'Dans l’ordre.', 'items' => ['a', 'b', 'c']];
        self::assertSame([], ScenarioValidator::problems(ScenarioFixture::data(['T2' => ['steps' => [$order + ['answer' => [2, 0, 1]]]]])));
        self::assertSame(['T2/chronologie : l’ordre attendu ne place pas chaque élément une fois'], ScenarioValidator::problems(ScenarioFixture::data(['T2' => ['steps' => [$order + ['answer' => [2, 0, 0]]]]])));
        self::assertSame(['T2/chronologie : l’ordre attendu ne place pas chaque élément une fois'], ScenarioValidator::problems(ScenarioFixture::data(['T2' => ['steps' => [$order + ['answer' => [1, 0]]]]])));
    }

    public function testAScenarioThatNeverAsksForThePasswordNeedsNoPieces(): void
    {
        $data = ScenarioFixture::data(['T1' => ['reward' => []], 'T4' => ['reward' => [], 'steps' => [['id' => 'trier', 'kind' => 'choice', 'prompt' => '?', 'options' => [['text' => 'Oui', 'ok' => true]]]]]], ['password' => []]);

        self::assertSame([], ScenarioValidator::problems($data));
    }
}
