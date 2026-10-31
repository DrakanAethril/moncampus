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
 * Black-out played through the SDK's routes (design/validated/serious-games-blackout.md §3): the
 * helpdesk's words on the cards, a line designated in a view outside Nadia's help, the night's
 * timeline put in order by the last ticket - corrected by the server, never shown - the clues
 * numbered as the inventory lists them, and the Testeur de câble at dawn. No password anywhere.
 */
class BlackoutPlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'blackout.student');
        $this->game = $this->installGame('blackout');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/blackout');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/blackout/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testTheHelpdeskShowsEachTicketsKindAndCategory(): void
    {
        $board = $this->call('GET', 'scenario');

        self::assertSame(36, $board['total']);
        self::assertSame([], $board['fragments'], 'no password to piece together');
        $card = self::arr($board, 'cards')[0] ?? null;
        self::assertIsArray($card);
        self::assertSame(['T01', 'incident', 'tres-haute', 'Réseau › Interfaces'], [$card['id'], $card['kind'], $card['priority'], $card['category']]);

        $ticket = self::arr($this->call('POST', 'tickets', ['ticket' => 'T01']), 'ticket');
        self::assertSame('Réseau › Interfaces', $ticket['category']);
    }

    public function testTheNightStartsAgainFromTheBeginningAtAnyTime(): void
    {
        $this->call('GET', 'scenario');
        $this->playTicket('T01');
        $this->call('POST', 'tickets', ['ticket' => 'T02']);
        $this->call('POST', 'progress', ['data' => ['prologue' => true, 'mute' => true]]);
        $before = $this->call('GET', 'progress');

        $this->call('POST', 'scenario/new-game');

        $board = $this->call('GET', 'scenario');
        self::assertFalse($board['finished']);
        self::assertSame(1, $board['act']);
        self::assertSame([], array_column(array_filter(self::arr($board, 'cards'), static fn (mixed $c): bool => \is_array($c) && \in_array($c['status'], ['done', 'doing'], true)), 'id'), 'no ticket closed nor under way');
        self::assertContains('T01', array_column(array_filter(self::arr($board, 'cards'), static fn (mixed $c): bool => \is_array($c) && 'todo' === $c['status']), 'id'));
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
        while (!$board['finished'] && $guard++ < 60) {
            $open = array_values(array_filter(self::arr($board, 'cards'), static fn (mixed $c): bool => \is_array($c) && \in_array($c['status'], ['todo', 'doing'], true)));
            self::assertLessThanOrEqual(4, \count($open), 'four open tickets at most');
            $id = \is_array($open[0] ?? null) && \is_string($open[0]['id'] ?? null) ? $open[0]['id'] : self::fail('no open ticket');
            $end = 'T34' === $id ? $this->playTheConnections() : ('T36' === $id ? $this->playTheFinal() : $this->playTicket($id));
            $rewards = [...$rewards, ...array_column(self::arr($end, 'rewards'), 'code')];
            if (isset($end['clueIndex'])) {
                $clues[] = $end['clueIndex'];
            }
            $board = $this->call('GET', 'scenario');
        }

        self::assertTrue($board['finished']);
        self::assertSame([1, 2, 3, 4, 5], $clues, 'each clue numbered by its place in the inventory');
        self::assertCount(5, self::arr($board, 'clues'));
        self::assertContains('testeur-cable', $rewards);
        self::assertContains('pin-ghost', $rewards);
        self::assertContains('card-blackout-acte9', $rewards);
        self::assertCount(11, $rewards, 'nine cards, the tester and the pin');
        $progress = $this->call('GET', 'progress');
        self::assertSame(36, $progress['lit']);
        self::assertSame('Régisseur·se de la nuit', $progress['grade']);
        $tester = array_values(array_filter(self::arr($progress, 'rewards'), static fn (mixed $r): bool => \is_array($r) && 'testeur-cable' === $r['code']))[0] ?? null;
        self::assertIsArray($tester);
        self::assertSame('gold', $tester['finish'], 'thirty-four tickets with three stars');
    }

    /**
     * T34: the connection that leaves the room is designated in r1's conntrack, a view of the game -
     * a step of its own, outside the help.
     *
     * @return array<string, mixed>
     */
    private function playTheConnections(): array
    {
        $start = $this->call('POST', 'tickets', ['ticket' => 'T34']);
        $session = self::int($start, 'session');
        $steps = self::arr(self::arr($start, 'ticket'), 'steps');
        self::assertSame(['id' => 'designer', 'kind' => 'line', 'prompt' => 'Clique sur la ligne qui montre où le Pi envoyait ses données.', 'label' => 'Désigner', 'view' => 'conntrack'], $steps[0] ?? null);

        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'designer', 'input' => 'udp  src=10.20.20.66 dst=10.20.10.6 sport=41022 dport=53 [ASSURED]']);
        self::assertFalse($wrong['ok']);
        self::assertSame('nadia', self::arr($wrong, 'reaction')['who'] ?? null);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'designer', 'input' => 'udp  src=10.20.20.66 dst=198.51.100.23 sport=51820 dport=51820 [ASSURED] · 14 Mo envoyés']);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'quoi', 'input' => 1]);

        return $this->call('POST', 'ticket-sessions/'.$session.'/end');
    }

    /**
     * T36: the repair, its solution, then the timeline - its items come in the order written, never
     * in the right one, and a wrong order costs a star.
     *
     * @return array<string, mixed>
     */
    private function playTheFinal(): array
    {
        $start = $this->call('POST', 'tickets', ['ticket' => 'T36']);
        $session = self::int($start, 'session');
        $order = self::arr(self::arr(self::arr($start, 'ticket'), 'steps'), 2);
        self::assertSame('order', $order['kind']);
        self::assertCount(6, self::arr($order, 'items'));
        self::assertArrayNotHasKey('answer', $order);

        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => true, 'failed' => []]]);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'solution', 'input' => 1]);
        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'chronologie', 'input' => [0, 1, 2, 3, 4, 5]]);
        self::assertFalse($wrong['ok']);
        self::assertSame('nadia', self::arr($wrong, 'reaction')['who'] ?? null);
        $right = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'chronologie', 'input' => [3, 1, 4, 0, 2, 5]]);
        self::assertTrue($right['ok']);
        self::assertTrue($right['done']);

        $end = $this->call('POST', 'ticket-sessions/'.$session.'/end');
        self::assertSame(2, $end['quality']);
        self::assertTrue($end['finished']);

        return $end;
    }

    /**
     * Plays a ticket without a mistake, with the answers only the server knows.
     *
     * @return array<string, mixed> the end of the ticket
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
        $this->client->request($method, '/serious-games/api/blackout/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
