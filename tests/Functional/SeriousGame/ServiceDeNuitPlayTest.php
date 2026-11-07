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
 * Service de nuit played through the SDK's routes (design/validated/serious-games-service-de-nuit.md
 * §3): one night at a time, a night served as {passed, failed} then one question - the site itself never
 * reaches the server as an answer, it travels as the game's display state -, Augustin's housekeeping
 * notice read line by line, no clue at all (the red thread is the game's optional epilogue), and the key
 * of room 12 once the 28 nights are served.
 */
class ServiceDeNuitPlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'nuit.student');
        $this->game = $this->installGame('service-de-nuit');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/service-de-nuit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/service-de-nuit/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testTheStreetOpensOneNightAtATime(): void
    {
        $board = $this->call('GET', 'scenario');

        self::assertSame(28, $board['total']);
        self::assertSame(1, $board['capacity']);
        self::assertSame([], $board['fragments'], 'no password to piece together');
        $cards = self::arr($board, 'cards');
        self::assertCount(1, $cards, 'the site is one: one night on the board');
        $card = $cards[0] ?? null;
        self::assertIsArray($card);
        self::assertSame(['N01', 'mireille', 'La loge › L\'heure'], [$card['id'], $card['requester'], $card['category']]);
    }

    public function testANightIsServedAsTheVisitorsThatFailedAndNothingElse(): void
    {
        $this->call('GET', 'scenario');
        $start = $this->call('POST', 'tickets', ['ticket' => 'N01']);
        $session = self::int($start, 'session');
        $steps = self::arr(self::arr($start, 'ticket'), 'steps');
        self::assertSame(['fix', 'choice'], array_column($steps, 'kind'), 'the site, then one question: no explanation forced after it');
        $fix = self::arr($steps, 0);
        self::assertSame(['servir', 'fix'], [$fix['id'], $fix['kind']]);
        $veille = self::arr($steps, 1);
        self::assertSame('veille', $veille['view'], 'the game draws the code of « la veille » from its own half');
        self::assertArrayNotHasKey('answer', $veille);

        $refused = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'servir', 'input' => ['passed' => false, 'failed' => ['Une cliente à 13 h 45']]]);
        self::assertFalse($refused['ok']);

        $accepted = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'servir', 'input' => ['passed' => true, 'failed' => []]]);
        self::assertTrue($accepted['ok']);
        self::assertSame(1, $accepted['next'], 'the question comes after the site');
    }

    public function testTheSiteTravelsAsTheDisplayStateAndANewGameStartsFromAugustinsPage(): void
    {
        $this->call('GET', 'scenario');
        $this->playTicket('N01');
        $site = ['accueil.php' => str_repeat("<p>Il est <?php echo date('H:i'); ?>.</p>\n", 600)];
        $this->call('POST', 'progress', ['data' => ['prologue' => true, 'mute' => true, 'site' => $site, 'notions' => ['echo' => ['pts' => 1, 'shaky' => false, 'exos' => []]]]]);

        $saved = self::arr(self::arr($this->call('GET', 'progress'), 'data'), 'site');
        self::assertSame($site, $saved, 'a site of some 25 KB comes back whole');

        $this->call('POST', 'scenario/new-game');

        $progress = $this->call('GET', 'progress');
        self::assertSame(['mute' => true], $progress['data'], 'the site goes with the game: Augustin\'s page again, the sound as it was');
    }

    public function testTheWholeGameEndsWithTheKeyOfRoom12AndNoClue(): void
    {
        $board = $this->call('GET', 'scenario');
        $rewards = [];
        $clues = [];
        $guard = 0;
        while (!$board['finished'] && $guard++ < 60) {
            $open = array_values(array_filter(self::arr($board, 'cards'), static fn (mixed $c): bool => \is_array($c) && \in_array($c['status'], ['todo', 'doing'], true)));
            self::assertCount(1, $open, 'one night at a time');
            $id = $open[0]['id'] ?? null;
            self::assertIsString($id);
            $end = $this->playTicket($id);
            $rewards = [...$rewards, ...array_column(self::arr($end, 'rewards'), 'code')];
            if (isset($end['clueIndex'])) {
                $clues[] = $end['clueIndex'];
            }
            $board = $this->call('GET', 'scenario');
        }

        self::assertTrue($board['finished']);
        self::assertSame([], $clues, 'the nights give no clue: the red thread is the optional epilogue\'s');
        self::assertContains('cle-12', $rewards);
        self::assertContains('pin-neon', $rewards);
        self::assertContains('card-service-de-nuit-acte5', $rewards);
        self::assertCount(7, $rewards, 'five cards, the key and the pin');
        $progress = $this->call('GET', 'progress');
        self::assertSame(28, $progress['lit']);
        self::assertSame('Gardien·ne des Quatre-Vents', $progress['grade']);
        $key = array_values(array_filter(self::arr($progress, 'rewards'), static fn (mixed $r): bool => \is_array($r) && 'cle-12' === $r['code']))[0] ?? null;
        self::assertIsArray($key);
        self::assertSame('gold', $key['finish'], 'twenty-eight nights with three stars');
    }

    public function testAugustinsCodeIsReadWithoutASiteToServe(): void
    {
        $this->call('GET', 'scenario');
        for ($n = 1; $n <= 16; ++$n) {
            $this->playTicket(\sprintf('N%02d', $n));
        }
        $start = $this->call('POST', 'tickets', ['ticket' => 'N17']);
        $session = self::int($start, 'session');
        $steps = self::arr(self::arr($start, 'ticket'), 'steps');
        self::assertSame(['line', 'choice', 'choice'], array_column($steps, 'kind'));
        self::assertSame([], self::arr($start, 'guide'), 'no help on a night of reading');

        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'designer', 'input' => '$jour = (int) date(\'N\');']);
        self::assertFalse($wrong['ok']);
        self::assertSame('sami', self::arr($wrong, 'reaction')['who'] ?? null);
        $right = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'designer', 'input' => "    echo '<p>Ménage des étages en cours.</p>';"]);
        self::assertTrue($right['ok']);
    }

    /**
     * Plays a night without a mistake, with the answers only the server knows.
     *
     * @return array<string, mixed> the end of the night
     */
    private function playTicket(string $id): array
    {
        $ticket = SeriousGameScenarioPlay::scenario($this->game)->ticket($id) ?? self::fail('night '.$id);
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
            ScenarioStepKind::Line => $step->answers[0],
            ScenarioStepKind::Order => $step->order,
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
        $this->client->request($method, '/serious-games/api/service-de-nuit/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
