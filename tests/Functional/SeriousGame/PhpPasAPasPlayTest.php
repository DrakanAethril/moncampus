<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\User;
use App\SeriousGame\Scenario\ScenarioStep;
use App\SeriousGame\Scenario\ScenarioStepKind;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * PHP pas à pas played through the SDK's routes (design/validated/serious-games-php-pas-a-pas.md §3): a
 * level opens once the one before is done, practises, follows, asks, writes three programs, then recalls
 * - its steps in the order written (`free_order`) -, a program reaches the server as {passed, failed}
 * only, a mastery challenge never blocks the way, and the final test gives the prism.
 */
class PhpPasAPasPlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'ppp.student');
        $this->game = $this->installGame('php-pas-a-pas');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/php-pas-a-pas');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/php-pas-a-pas/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testTheFirstLevelOpensAlone(): void
    {
        $board = $this->call('GET', 'scenario');

        self::assertSame(77, $board['total'], '52 levels, 24 mastery challenges, the final test');
        self::assertSame([], $board['fragments'], 'no password to piece together');
        $cards = self::arr($board, 'cards');
        self::assertCount(1, $cards);
        self::assertSame('N01', self::arr($cards, 0)['id']);
    }

    public function testALevelOfTheCorePractisesFollowsAsksWritesThenRecalls(): void
    {
        $ticket = SeriousGameScenarioPlay::scenario($this->game)->ticket('N16') ?? self::fail('N16');
        $ids = array_map(static fn (ScenarioStep $s): string => $s->id, $ticket->steps);
        self::assertSame(['predire', 'suivre', 'comprendre', 'completer', 'reparer', 'ecrire1', 'ecrire2', 'ecrire3', 'rappel1', 'rappel2'], $ids);

        $this->call('GET', 'scenario');
        $start = $this->call('POST', 'tickets', ['ticket' => 'N01']);
        $session = self::int($start, 'session');
        $steps = self::arr(self::arr($start, 'ticket'), 'steps');
        self::assertSame(['predire', 'comprendre', 'completer', 'reparer', 'ecrire1', 'ecrire2', 'ecrire3'], array_column($steps, 'id'), 'no table to follow and no reminder before level 6');
        self::assertArrayNotHasKey('answer', self::arr($steps, 1), 'the game never holds the right option');

        $refused = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'predire', 'input' => ['passed' => false, 'failed' => ['Prédiction']]]);
        self::assertFalse($refused['ok']);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'predire', 'input' => ['passed' => true, 'failed' => []]]);

        $step = SeriousGameScenarioPlay::scenario($this->game)->ticket('N01')?->step('comprendre') ?? self::fail('comprendre');
        $wrongRank = (int) array_search(false, array_column($step->options, 'ok'), true);
        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'comprendre', 'input' => $this->shown($this->game, $start, 'comprendre', $wrongRank)]);
        self::assertFalse($wrong['ok']);
        self::assertIsString($wrong['misconception'] ?? null, 'a wrong explanation names its misconception');
        $right = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'comprendre', 'input' => $this->shown($this->game, $start, 'comprendre', (int) array_search(true, array_column($step->options, 'ok'), true))]);
        self::assertTrue($right['ok']);
        self::assertSame(2, $right['next'], 'then the program to complete');
    }

    public function testEveryMisconceptionHasItsLabel(): void
    {
        /** @var array{scenario: array{misconceptions: array<string, string>}} $manifest */
        $manifest = Yaml::parseFile(static::getContainer()->getParameter('kernel.project_dir').'/config/serious_games/php-pas-a-pas/game.yaml');
        $labels = $manifest['scenario']['misconceptions'];
        foreach (SeriousGameScenarioPlay::scenario($this->game)->misconceptions() as $id) {
            self::assertArrayHasKey($id, $labels, 'a teacher reads « '.$id.' » in « Notions à reprendre »');
        }
    }

    public function testAMasteryChallengeNeverBlocksTheWay(): void
    {
        $this->call('GET', 'scenario');
        $this->playTicket('N01');
        $this->playTicket('N02');

        self::assertSame(['M01', 'N03'], $this->openIds(), 'the challenge of the first two levels, and the next level beside it');

        $this->playTicket('N03');
        self::assertSame(['M01', 'N04'], $this->openIds(), 'the challenge waits, the way goes on');

        $start = $this->call('POST', 'tickets', ['ticket' => 'M01']);
        self::assertSame(['maitrise'], array_column(self::arr(self::arr($start, 'ticket'), 'steps'), 'id'), 'a challenge is one measured step');
        $session = self::int($start, 'session');
        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'maitrise', 'input' => ['passed' => false, 'failed' => ['Défi de maîtrise : essai faux']]]);
        self::assertFalse($wrong['ok']);
        $won = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'maitrise', 'input' => ['passed' => true, 'failed' => []]]);
        self::assertTrue($won['ok']);
        $end = $this->call('POST', 'ticket-sessions/'.$session.'/end');
        self::assertSame(2, $end['quality'], 'a wrong try is an error: two stars');
    }

    public function testALevelDoneIsReadAgainWithItsCorrections(): void
    {
        $this->call('GET', 'scenario');
        self::assertSame(['error' => 'ticket-not-closed'], $this->refused('tickets/review', ['ticket' => 'N01']), 'the answers stay secret until the level is done');
        $this->playTicket('N01');

        $review = $this->call('POST', 'tickets/review', ['ticket' => 'N01']);
        $steps = self::arr($review, 'steps');
        self::assertSame(['predire', 'comprendre', 'completer', 'reparer', 'ecrire1', 'ecrire2', 'ecrire3'], array_column($steps, 'id'));
        $options = self::arr(self::arr($steps, 1), 'options');
        self::assertSame([true, false, false, false], array_column($options, 'ok'), 'in the order written, the right one marked');
        self::assertIsString(self::arr($options, 1)['why'], 'and why each wrong one is wrong');
        self::assertArrayNotHasKey('options', self::arr($steps, 0), 'a program is the game\'s to show');
    }

    public function testAReplayRaisesTheStarsAndLowersNothing(): void
    {
        $this->call('GET', 'scenario');
        $this->playTicket('N01', mistakes: 1);
        self::assertSame(2, $this->stars('N01'), 'one mistake: two stars');
        self::assertSame(['error' => 'ticket-not-closed'], $this->refused('tickets/replay', ['ticket' => 'N02']), 'only a level done is played again');

        $replay = $this->call('POST', 'tickets/replay', ['ticket' => 'N01']);
        self::assertTrue($replay['replay']);
        self::assertSame(0, $replay['step'], 'from the first step');
        $session = self::int($replay, 'session');
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'predire', 'input' => ['passed' => true, 'failed' => []]]);
        self::assertSame(1, self::int($this->call('POST', 'tickets/replay', ['ticket' => 'N01']), 'step'), 'a replay left is resumed');
        self::assertSame(0, self::int($this->call('POST', 'tickets/replay', ['ticket' => 'N01', 'fresh' => true]), 'step'), 'or started over');
        self::assertSame(['N02'], $this->openIds(), 'the level stays done while it is replayed: nothing behind it closes again');

        $worse = $this->replayTicket('N01', mistakes: 3);
        self::assertSame(1, $worse['stars']);
        self::assertSame(2, $worse['quality'], 'a worse go lowers nothing');
        self::assertFalse($worse['improved']);
        self::assertSame(2, $this->stars('N01'));

        $better = $this->replayTicket('N01');
        self::assertSame(3, $better['stars']);
        self::assertSame(3, $better['quality']);
        self::assertTrue($better['improved']);
        self::assertSame(3, $this->stars('N01'), 'the board shows the stars raised');
        $unit = array_values(array_filter(self::arr($this->call('GET', 'progress'), 'units'), static fn (mixed $u): bool => \is_array($u) && 'N01' === $u['code']))[0] ?? null;
        self::assertIsArray($unit);
        self::assertSame(3, $unit['best'], 'and so does what the teachers read');
    }

    public function testRestartingTheGameKeepsTheNotebookAndEndsTheReplays(): void
    {
        $this->call('GET', 'scenario');
        $this->playTicket('N01');
        $session = self::int($this->call('POST', 'tickets/replay', ['ticket' => 'N01']), 'session');
        $this->call('POST', 'progress', ['data' => ['mute' => true, 'answers' => ['N01' => ['ecrire1' => ['a' => 'echo "PHP pas à pas";']]], 'nseen' => ['echo' => 1], 'drafts' => ['N02' => ['predire' => 'x']], 'miss' => ['M01' => 2]]]);

        $this->call('POST', 'scenario/new-game');

        $data = self::arr($this->call('GET', 'progress'), 'data');
        self::assertEqualsCanonicalizing(['mute', 'answers', 'nseen'], array_keys($data), 'the sound, the archive of answers and the notebook stay; the game\'s progress goes');
        self::assertSame(['error' => 'session-ended'], $this->refused('ticket-sessions/'.$session.'/step', ['step' => 'predire', 'input' => ['passed' => true, 'failed' => []]]));
    }

    /**
     * The 77 tickets are played through the service the routes call, not over HTTP: some 800 requests
     * on one test kernel (reboot disabled) nest the security listeners deeper at each request, until
     * Xdebug stops the stack. The routes themselves are played by the tests above.
     */
    public function testTheWholeGameEndsOnThePrism(): void
    {
        $play = static::getContainer()->get(SeriousGameScenarioPlay::class);
        $scenario = SeriousGameScenarioPlay::scenario($this->game);
        $rewards = [];
        $guard = 0;
        while ($guard++ < 100) {
            $open = [];
            foreach (self::arr($play->board($this->student, $this->game), 'cards') as $card) {
                if (\is_array($card) && \in_array($card['status'] ?? null, ['todo', 'doing'], true) && \is_string($card['id'] ?? null)) {
                    $open[] = $card['id'];
                }
            }
            if ([] === $open) {
                break;
            }
            usort($open, static fn (string $a, string $b): int => array_search($a, $scenario->ticketIds(), true) <=> array_search($b, $scenario->ticketIds(), true));
            $ticket = $scenario->ticket($open[0]) ?? self::fail('ticket '.$open[0]);
            $start = $play->startTicket($this->student, $this->game, $ticket->id);
            $session = $play->ownedTicketSession($this->student, $this->game, self::int($start, 'session'));
            foreach (\array_slice($ticket->steps, self::int($start, 'step')) as $step) {
                $play->submitStep($this->student, $session, $step->id, $this->rightInput($step, $start));
            }
            $end = $play->endTicket($this->student, $session);
            $rewards = [...$rewards, ...array_column(self::arr($end, 'rewards'), 'code')];
        }

        $board = $this->call('GET', 'scenario');
        self::assertTrue($board['finished']);
        self::assertContains('prisme', $rewards);
        self::assertContains('pin-nebuleuse', $rewards);
        self::assertContains('card-php-pas-a-pas-acte1', $rewards);
        self::assertContains('card-php-pas-a-pas-acte8', $rewards);
        self::assertCount(10, $rewards, 'eight cards, the prism and the pin');
        $progress = $this->call('GET', 'progress');
        self::assertSame(77, $progress['lit']);
        self::assertSame('Maître·sse du PHP', $progress['grade']);
        $prism = array_values(array_filter(self::arr($progress, 'rewards'), static fn (mixed $r): bool => \is_array($r) && 'prisme' === $r['code']))[0] ?? null;
        self::assertIsArray($prism);
        self::assertSame('gold', $prism['finish'], 'every level and challenge with three stars');
    }

    /** @return list<string> the tickets on the board not yet closed, in the scenario's order */
    private function openIds(): array
    {
        $board = $this->call('GET', 'scenario');
        $ids = [];
        foreach (self::arr($board, 'cards') as $card) {
            if (\is_array($card) && \in_array($card['status'] ?? null, ['todo', 'doing'], true) && \is_string($card['id'] ?? null)) {
                $ids[] = $card['id'];
            }
        }
        $order = SeriousGameScenarioPlay::scenario($this->game)->ticketIds();
        usort($ids, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $ids;
    }

    /**
     * Plays a ticket with the answers only the server knows, after so many refused deliveries.
     *
     * @return array<string, mixed> the end of the ticket
     */
    private function playTicket(string $id, int $mistakes = 0): array
    {
        return $this->playSteps($id, $this->call('POST', 'tickets', ['ticket' => $id]), $mistakes);
    }

    /**
     * Replays a closed ticket from its first step, after so many refused deliveries.
     *
     * @return array<string, mixed> the end of the replay
     */
    private function replayTicket(string $id, int $mistakes = 0): array
    {
        return $this->playSteps($id, $this->call('POST', 'tickets/replay', ['ticket' => $id, 'fresh' => true]), $mistakes);
    }

    /**
     * @param array<string, mixed> $start the answer that opened the ticket
     *
     * @return array<string, mixed>
     */
    private function playSteps(string $id, array $start, int $mistakes): array
    {
        $ticket = SeriousGameScenarioPlay::scenario($this->game)->ticket($id) ?? self::fail('ticket '.$id);
        $session = self::int($start, 'session');
        foreach (\array_slice($ticket->steps, self::int($start, 'step')) as $step) {
            for (; $mistakes > 0 && ScenarioStepKind::Fix === $step->kind; --$mistakes) {
                $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => $step->id, 'input' => ['passed' => false, 'failed' => ['Essai faux']]]);
            }
            $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => $step->id, 'input' => $this->rightInput($step, $start)]);
        }

        return $this->call('POST', 'ticket-sessions/'.$session.'/end');
    }

    /** The stars the board shows on a ticket. */
    private function stars(string $id): ?int
    {
        foreach (self::arr($this->call('GET', 'scenario'), 'cards') as $card) {
            if (\is_array($card) && $id === $card['id']) {
                self::assertTrue(null === $card['quality'] || \is_int($card['quality']));

                return $card['quality'];
            }
        }

        return null;
    }

    /**
     * A call the server refuses: its error.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function refused(string $path, array $body): array
    {
        $this->client->request('POST', '/serious-games/api/php-pas-a-pas/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], (string) json_encode($body));
        self::assertResponseStatusCodeSame(409, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @param array<array-key, mixed> $start the answer to `POST tickets` */
    private function rightInput(ScenarioStep $step, array $start): mixed
    {
        return match ($step->kind) {
            ScenarioStepKind::Fix => ['passed' => true, 'failed' => []],
            default => $this->shown($this->game, $start, $step->id, (int) array_search(true, array_column($step->options, 'ok'), true)),
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
        $this->client->request($method, '/serious-games/api/php-pas-a-pas/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
