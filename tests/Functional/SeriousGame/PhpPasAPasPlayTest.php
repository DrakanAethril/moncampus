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
            $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => $step->id, 'input' => $this->rightInput($step, $start)]);
        }

        return $this->call('POST', 'ticket-sessions/'.$session.'/end');
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
