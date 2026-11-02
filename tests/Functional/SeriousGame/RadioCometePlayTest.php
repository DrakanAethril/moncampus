<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\User;
use App\SeriousGame\Scenario\ScenarioStep;
use App\SeriousGame\Scenario\ScenarioStepKind;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Radio-Comète played through the SDK's routes (design/validated/serious-games-radio-comete.md §3):
 * the rack's words on the cards, a script delivered as {passed, failed} - the script itself never
 * reaches the server -, a line designated in a view outside Colette's help, the report's timeline
 * put in order by the last step - corrected by the server, never shown - the five clues numbered as
 * the notebook lists them, and the Fader d'or at dawn. No password anywhere.
 */
class RadioCometePlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'radiocomete.student');
        $this->game = $this->installGame('radio-comete');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/radio-comete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/radio-comete/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testTheRackShowsEachStepsKindAndCategory(): void
    {
        $board = $this->call('GET', 'scenario');

        self::assertSame(40, $board['total']);
        self::assertSame([], $board['fragments'], 'no password to piece together');
        $card = self::arr($board, 'cards')[0] ?? null;
        self::assertIsArray($card);
        self::assertSame(['E01', 'demande', 'haute', "Sauvegardes › Lecture d'un script"], [$card['id'], $card['kind'], $card['priority'], $card['category']]);
    }

    public function testAScriptIsDeliveredAsTheMachinesThatFailedAndNothingElse(): void
    {
        $this->call('GET', 'scenario');
        $this->playTicket('E01');
        $start = $this->call('POST', 'tickets', ['ticket' => 'E02']);
        $session = self::int($start, 'session');

        $refused = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => false, 'failed' => ['un nom avec une apostrophe et des accents']]]);
        self::assertFalse($refused['ok']);

        $accepted = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => true, 'failed' => []]]);
        self::assertTrue($accepted['ok']);
        self::assertSame(1, $accepted['next'], 'the explanation comes after the script');
    }

    public function testTheNightStartsAgainFromTheBeginningAtAnyTime(): void
    {
        $this->call('GET', 'scenario');
        $this->playTicket('E01');
        $this->call('POST', 'tickets', ['ticket' => 'E02']);
        $this->call('POST', 'progress', ['data' => ['prologue' => true, 'mute' => true]]);
        $before = $this->call('GET', 'progress');

        $this->call('POST', 'scenario/new-game');

        $board = $this->call('GET', 'scenario');
        self::assertFalse($board['finished']);
        self::assertSame(1, $board['act']);
        self::assertSame([], array_column(array_filter(self::arr($board, 'cards'), static fn (mixed $c): bool => \is_array($c) && \in_array($c['status'], ['done', 'doing'], true)), 'id'), 'no step closed nor under way');
        $progress = $this->call('GET', 'progress');
        self::assertSame(['mute' => true], $progress['data'], 'the game starts on its title again, the sound as it was');
        self::assertSame($before['rewards'], $progress['rewards'], 'the collection stays');
    }

    public function testTheWholeNightEndsOnTheTimelineOfTheReport(): void
    {
        $board = $this->call('GET', 'scenario');
        $rewards = [];
        $clues = [];
        $guard = 0;
        while (!$board['finished'] && $guard++ < 80) {
            $open = array_values(array_filter(self::arr($board, 'cards'), static fn (mixed $c): bool => \is_array($c) && \in_array($c['status'], ['todo', 'doing'], true)));
            self::assertLessThanOrEqual(4, \count($open), 'four open steps at most');
            $id = \is_array($open[0] ?? null) && \is_string($open[0]['id'] ?? null) ? $open[0]['id'] : self::fail('no open step');
            $end = 'E40' === $id ? $this->playTheFinal() : $this->playTicket($id);
            $rewards = [...$rewards, ...array_column(self::arr($end, 'rewards'), 'code')];
            if (isset($end['clueIndex'])) {
                $clues[] = $end['clueIndex'];
            }
            $board = $this->call('GET', 'scenario');
        }

        self::assertTrue($board['finished']);
        self::assertSame([1, 2, 3, 4, 5], $clues, 'each clue numbered by its place in the notebook');
        self::assertCount(5, self::arr($board, 'clues'));
        self::assertContains('fader-or', $rewards);
        self::assertContains('pin-comete', $rewards);
        self::assertContains('card-radio-comete-acte5', $rewards);
        self::assertCount(7, $rewards, 'five cards, the fader and the pin');
        $progress = $this->call('GET', 'progress');
        self::assertSame(40, $progress['lit']);
        self::assertSame('Gardien·ne de la nuit', $progress['grade']);
        $fader = array_values(array_filter(self::arr($progress, 'rewards'), static fn (mixed $r): bool => \is_array($r) && 'fader-or' === $r['code']))[0] ?? null;
        self::assertIsArray($fader);
        self::assertSame('gold', $fader['finish'], 'thirty-nine steps with three stars');
    }

    /**
     * E40: the script, its explanation, then the timeline - its items come in the order written,
     * never in the right one, and a wrong order costs a star.
     *
     * @return array<string, mixed>
     */
    private function playTheFinal(): array
    {
        $start = $this->call('POST', 'tickets', ['ticket' => 'E40']);
        $session = self::int($start, 'session');
        $order = self::arr(self::arr(self::arr($start, 'ticket'), 'steps'), 2);
        self::assertSame('order', $order['kind']);
        self::assertCount(7, self::arr($order, 'items'));
        self::assertArrayNotHasKey('answer', $order);

        $ticket = SeriousGameScenarioPlay::scenario($this->game)->ticket('E40') ?? self::fail('ticket E40');
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => true, 'failed' => []]]);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'solution', 'input' => $this->rightInput($ticket->steps[1])]);
        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'chronologie', 'input' => [0, 1, 2, 3, 4, 5, 6]]);
        self::assertFalse($wrong['ok']);
        self::assertSame('colette', self::arr($wrong, 'reaction')['who'] ?? null);
        $right = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'chronologie', 'input' => $this->rightInput($ticket->steps[2])]);
        self::assertTrue($right['ok']);
        self::assertTrue($right['done']);

        $end = $this->call('POST', 'ticket-sessions/'.$session.'/end');
        self::assertSame(2, $end['quality']);
        self::assertTrue($end['finished']);

        return $end;
    }

    /**
     * Plays a step without a mistake, with the answers only the server knows.
     *
     * @return array<string, mixed> the end of the step
     */
    private function playTicket(string $id): array
    {
        $ticket = SeriousGameScenarioPlay::scenario($this->game)->ticket($id) ?? self::fail('ticket '.$id);
        $start = $this->call('POST', 'tickets', ['ticket' => $id]);
        $session = self::int($start, 'session');
        foreach (\array_slice($ticket->steps, self::int($start, 'step')) as $step) {
            $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => $step->id, 'input' => $this->rightInput($step)]);
        }

        return $this->call('POST', 'ticket-sessions/'.$session.'/end');
    }

    private function rightInput(ScenarioStep $step): mixed
    {
        return match ($step->kind) {
            ScenarioStepKind::Fix => ['passed' => true, 'failed' => []],
            ScenarioStepKind::Line => $step->answers[0],
            ScenarioStepKind::Order => $step->order,
            default => array_search(true, array_column($step->options, 'ok'), true),
        };
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function arr(array $data, string|int $key): array
    {
        self::assertIsArray($data[$key] ?? null, (string) $key);

        return $data[$key];
    }

    /** @param array<array-key, mixed> $data */
    private static function int(array $data, string $key): int
    {
        self::assertIsInt($data[$key] ?? null, $key);

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = []): array
    {
        $this->client->request($method, '/serious-games/api/radio-comete/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
