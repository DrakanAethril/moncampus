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
 * Rétro Rush played through the SDK's routes (design/validated/serious-games-retro-rush.md §3): the
 * forge's words on the cards, Jade answering a wrong line, the five clues of the stock file in the
 * order of the scenario, the migration script put in order by the last ticket - corrected by the
 * server, never shown - and the Cartouche d'or at the opening. No password anywhere.
 */
class RetroRushPlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'retro.student');
        $this->game = $this->installGame('retro-rush');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/retro-rush');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/retro-rush/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testTheForgeShowsEachIssuesKindAndCategory(): void
    {
        $board = $this->call('GET', 'scenario');

        self::assertSame(45, $board['total']);
        self::assertSame([], $board['fragments'], 'no password to piece together');
        $card = self::arr($board, 'cards')[0] ?? null;
        self::assertIsArray($card);
        self::assertSame(['R01', 'demande', 'Modélisation › Tableur'], [$card['id'], $card['kind'], $card['category']]);

        $start = $this->call('POST', 'tickets', ['ticket' => 'R01']);
        $session = self::int($start, 'session');
        $steps = self::arr(self::arr($start, 'ticket'), 'steps');
        self::assertSame(['line', 'tableur'], [self::arr($steps, 0)['kind'], self::arr($steps, 0)['view']]);
        self::assertArrayNotHasKey('answer', self::arr($steps, 0));

        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'designer', 'input' => 'J0413  Streets of Rage 2            MEGADRIVE       complet     40      2  Ferrand     06 12 34 56 78']);
        self::assertFalse($wrong['ok']);
        self::assertSame('jade', self::arr($wrong, 'reaction')['who'] ?? null);
    }

    public function testTheWholeMonthEndsOnTheMigrationScript(): void
    {
        $board = $this->call('GET', 'scenario');
        $rewards = [];
        $clues = [];
        $guard = 0;
        while (!$board['finished'] && $guard++ < 70) {
            $open = array_values(array_filter(self::arr($board, 'cards'), static fn (mixed $c): bool => \is_array($c) && \in_array($c['status'], ['todo', 'doing'], true)));
            self::assertLessThanOrEqual(4, \count($open), 'four open issues at most');
            $id = \is_array($open[0] ?? null) && \is_string($open[0]['id'] ?? null) ? $open[0]['id'] : self::fail('no open issue');
            $end = 'R45' === $id ? $this->playTheOpening() : $this->playTicket($id);
            $rewards = [...$rewards, ...array_column(self::arr($end, 'rewards'), 'code')];
            if (isset($end['clueIndex'])) {
                $clues[] = $end['clueIndex'];
            }
            $board = $this->call('GET', 'scenario');
        }

        self::assertTrue($board['finished']);
        self::assertSame([1, 2, 3, 4, 5], $clues, 'each clue numbered by its place in the stock file');
        self::assertCount(5, self::arr($board, 'clues'));
        self::assertContains('cartouche', $rewards);
        self::assertContains('pin-joystick', $rewards);
        self::assertContains('card-retro-rush-acte9', $rewards);
        self::assertCount(11, $rewards, 'nine cards, the cartridge and the pin');
        $progress = $this->call('GET', 'progress');
        self::assertSame(45, $progress['lit']);
        self::assertSame('Gardien·ne du stock', $progress['grade']);
        $cartridge = array_values(array_filter(self::arr($progress, 'rewards'), static fn (mixed $r): bool => \is_array($r) && 'cartouche' === $r['code']))[0] ?? null;
        self::assertIsArray($cartridge);
        self::assertSame('gold', $cartridge['finish'], 'forty-four issues with three stars');
    }

    /**
     * R45: the delivery, the review, then the migration script - its items come in the order
     * written, never in the right one, and a wrong order costs a star.
     *
     * @return array<string, mixed>
     */
    private function playTheOpening(): array
    {
        $start = $this->call('POST', 'tickets', ['ticket' => 'R45']);
        $session = self::int($start, 'session');
        $order = self::arr(self::arr(self::arr($start, 'ticket'), 'steps'), 2);
        self::assertSame('order', $order['kind']);
        self::assertCount(6, self::arr($order, 'items'));
        self::assertArrayNotHasKey('answer', $order);

        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'livrer', 'input' => ['passed' => true, 'failed' => []]]);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'revue', 'input' => 0]);
        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'migration', 'input' => [2, 1, 4, 3, 5, 0]]);
        self::assertFalse($wrong['ok']);
        self::assertSame('jade', self::arr($wrong, 'reaction')['who'] ?? null);
        $right = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'migration', 'input' => [1, 4, 2, 3, 5, 0]]);
        self::assertTrue($right['ok']);
        self::assertTrue($right['done']);

        $end = $this->call('POST', 'ticket-sessions/'.$session.'/end');
        self::assertSame(2, $end['quality']);
        self::assertTrue($end['finished']);

        return $end;
    }

    /**
     * Plays an issue without a mistake, with the answers only the server knows.
     *
     * @return array<string, mixed> the end of the issue
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
        $this->client->request($method, '/serious-games/api/retro-rush/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
