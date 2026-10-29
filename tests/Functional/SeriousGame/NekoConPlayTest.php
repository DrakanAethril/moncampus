<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\User;
use App\Repository\SeriousGameAnswerRepository;
use App\Repository\SeriousGameProgressRepository;
use App\SeriousGame\Scenario\ScenarioStep;
use App\SeriousGame\Scenario\ScenarioStepKind;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Panique à la Neko-Con played through the SDK's routes (design/validated/serious-games-neko-con.md
 * §2, §3): the board of four and its graph, a ticket repaired then explained, Inès' help that
 * opens only once asked and costs stars, the game told no right answer, nothing of the code kept,
 * the game resumed from wherever, the site online with its Badge Staff, and « Nouvelle partie ».
 */
class NekoConPlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'neko.student');
        $this->game = $this->installGame('neko-con');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/neko-con');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/neko-con/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testTheBoardOpensFourTicketsOfTheFirstAct(): void
    {
        $board = $this->call('GET', 'scenario');

        self::assertSame(1, $board['act']);
        self::assertSame(4, $board['capacity']);
        self::assertSame(39, $board['total']);
        self::assertSame(['T01', 'T02', 'T03', 'T04'], array_column($board['cards'], 'id'));
        self::assertSame(['todo'], array_values(array_unique(array_column($board['cards'], 'status'))));
        self::assertSame([null, null, null, null, null], $board['fragments']);
        self::assertFalse($board['actSeen']);
        self::assertSame(['apercu'], self::arr(self::arr($board, 'acts')[0] ?? [], 'tools'));

        $this->call('POST', 'scenario/act-seen', ['act' => 1]);
        $this->call('POST', 'progress', ['data' => ['mute' => true, 'actsSeen' => []]]);
        self::assertTrue($this->call('GET', 'scenario')['actSeen'], 'the game’s own save keeps the acts the server saw presented');
    }

    public function testATicketComesWithoutItsAnswers(): void
    {
        $this->call('GET', 'scenario');
        $ticket = $this->call('POST', 'tickets', ['ticket' => 'T01']);

        self::assertSame(0, $ticket['step']);
        $steps = self::arr(self::arr($ticket, 'ticket'), 'steps');
        self::assertSame(['corriger', 'expliquer'], array_column($steps, 'id'));
        $json = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('misconception', $json);
        self::assertStringNotContainsString('"ok"', $json);
        self::assertStringNotContainsString('reaction', $json);
        self::assertCount(4, self::arr(\is_array($steps[1] ?? null) ? $steps[1] : [], 'options'));
        self::assertSame('doing', $this->card('T01')['status']);

        $this->call('POST', 'tickets', ['ticket' => 'T06'], 409);
    }

    public function testARepairedTicketIsExplainedThenClosedWithItsStars(): void
    {
        $this->call('GET', 'scenario');
        $session = self::int($this->call('POST', 'tickets', ['ticket' => 'T01']), 'session');

        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'expliquer', 'input' => 1], 409);
        $refused = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => false, 'failed' => ['Le slogan s’affiche avec ses accents'], 'files' => ['index.html' => '<html>secret</html>']]]);
        self::assertFalse($refused['ok']);
        self::assertSame(0, $refused['next']);

        // From another computer: a new opening, the same step.
        $session = self::int($this->call('POST', 'tickets', ['ticket' => 'T01']), 'session');
        $accepted = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => true, 'failed' => []]]);
        self::assertTrue($accepted['ok']);
        self::assertSame(1, $accepted['next']);
        self::assertFalse($accepted['done']);
        self::assertSame(1, $this->call('POST', 'tickets', ['ticket' => 'T01'])['step'], 'the game resumes where it was left');

        $wrong = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'expliquer', 'input' => 0]);
        self::assertFalse($wrong['ok']);
        self::assertSame('police-accents', $wrong['misconception']);
        self::assertSame('ines', self::arr($wrong, 'reaction')['who'] ?? null);
        $right = $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'expliquer', 'input' => 1]);
        self::assertTrue($right['ok']);
        self::assertTrue($right['done']);

        $end = $this->call('POST', 'ticket-sessions/'.$session.'/end');
        self::assertSame('done', $end['status']);
        self::assertSame(2, $end['quality'], 'a refused delivery and a wrong explanation: two errors');
        self::assertSame(['T05'], array_column($end['newCards'], 'id'));
        self::assertSame(2, $this->card('T01')['quality']);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => true]], 409);

        $inputs = array_map(static fn ($a): ?string => $a->getInput(), static::getContainer()->get(SeriousGameAnswerRepository::class)->findAll());
        self::assertContains('Le slogan s’affiche avec ses accents', $inputs, 'a refused delivery keeps its failed criteria');
        self::assertStringNotContainsString('secret', implode('|', array_filter($inputs)), 'the code is never kept');
    }

    public function testInesHelpOpensOnlyOnceAskedAndCostsStarsNotItsErrors(): void
    {
        $this->call('GET', 'scenario');
        $session = self::int($this->call('POST', 'tickets', ['ticket' => 'T02']), 'session');

        $this->call('POST', 'ticket-sessions/'.$session.'/guide', ['step' => 'localiser', 'input' => '<p>'], 409);
        self::assertSame(['hints' => 1, 'guideStep' => 0], $this->call('POST', 'ticket-sessions/'.$session.'/hint'));
        $line = $this->call('POST', 'ticket-sessions/'.$session.'/guide', ['step' => 'localiser', 'input' => '<h1>Neko-Con</h1>']);
        self::assertFalse($line['ok']);
        self::assertSame(0, $line['next']);
        $line = $this->call('POST', 'ticket-sessions/'.$session.'/guide', ['step' => 'localiser', 'input' => '      <img class="affiche" src="img/Affiche.SVG" alt="…">']);
        self::assertTrue($line['ok']);
        self::assertSame(1, $line['next']);

        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'corriger', 'input' => ['passed' => true, 'failed' => []]]);
        $this->call('POST', 'ticket-sessions/'.$session.'/step', ['step' => 'expliquer', 'input' => 2]);
        $end = $this->call('POST', 'ticket-sessions/'.$session.'/end');
        self::assertSame(2, $end['quality'], 'one rung of help, and the error made during it does not count');
    }

    public function testTheDraftsOfAClosedTicketAreDropped(): void
    {
        $this->call('GET', 'scenario');
        $this->call('POST', 'progress', ['data' => ['drafts' => ['T04' => ['index.html' => '<a herf>'], 'T03' => ['index.html' => 'x']]]]);
        $this->playTicket('T04');

        $progress = static::getContainer()->get(SeriousGameProgressRepository::class)->findOneFor($this->student, $this->game) ?? self::fail('progress');
        self::assertSame(['T03' => ['index.html' => 'x']], $progress->getData()['drafts'] ?? null);
    }

    public function testTheSiteGoesOnlineWithItsBadgeAndANewGameKeepsWhatWasWon(): void
    {
        $board = $this->call('GET', 'scenario');
        $rewards = [];
        $guard = 0;
        while (!$board['finished'] && $guard++ < 60) {
            $open = array_values(array_filter($board['cards'], static fn (array $c): bool => \in_array($c['status'], ['todo', 'doing'], true)));
            self::assertLessThanOrEqual(4, \count($open), 'four open tickets at most');
            $end = $this->playTicket($open[0]['id']);
            $rewards = [...$rewards, ...array_column($end['rewards'], 'code')];
            $board = $this->call('GET', 'scenario');
        }

        self::assertTrue($board['finished']);
        self::assertSame(['Neko', 'Senpai', 'Ramen', '2026', '!'], $board['fragments']);
        self::assertContains('badge-staff', $rewards);
        self::assertContains('pin-neko', $rewards);
        self::assertContains('card-neko-con-acte9', $rewards);
        self::assertCount(11, $rewards, 'nine cards, the badge and the pin');
        $progress = $this->call('GET', 'progress');
        self::assertTrue($progress['finished']);
        self::assertSame(39, $progress['lit']);
        self::assertSame('Webmaster de la Neko-Con', $progress['grade']);
        $badge = array_values(array_filter($progress['rewards'], static fn (array $r): bool => 'badge-staff' === $r['code']))[0];
        self::assertSame('gold', $badge['finish'], 'every ticket with three stars');

        $this->call('POST', 'scenario/new-game');
        $board = $this->call('GET', 'scenario');
        self::assertFalse($board['finished']);
        self::assertSame(['T01', 'T02', 'T03', 'T04'], array_column(array_filter($board['cards'], static fn (array $c): bool => 'todo' === $c['status']), 'id'));
        $progress = $this->call('GET', 'progress');
        self::assertCount(11, $progress['rewards']);
        self::assertSame('Webmaster de la Neko-Con', $progress['grade'], 'the grade reached stays');
    }

    public function testANewGameWaitsForTheEnd(): void
    {
        $this->call('GET', 'scenario');
        $this->call('POST', 'scenario/new-game', [], 409);
    }

    public function testASeriesGameHasNoScenario(): void
    {
        $this->installGame('pompei', ['ROLE_STUDENT'], ['rosa']);
        $this->call('GET', 'scenario', [], 404, 'pompei');
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
            ScenarioStepKind::Password => 'NekoSenpaiRamen2026!',
            ScenarioStepKind::Line => $step->answers[0],
            default => array_search(true, array_column($step->options, 'ok'), true),
        };
    }

    /** @return array<string, mixed> */
    private function card(string $id): array
    {
        foreach (self::arr($this->call('GET', 'scenario'), 'cards') as $card) {
            if (\is_array($card) && $card['id'] === $id) {
                return $card;
            }
        }

        self::fail('no card '.$id);
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
    private function call(string $method, string $path, array $body = [], int $expected = 200, string $slug = 'neko-con'): array
    {
        $this->client->request($method, '/serious-games/api/'.$slug.'/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($expected, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
