<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameSession;
use App\Entity\SeriousGameUnitState;
use App\Entity\User;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameSessionRepository;
use App\SeriousGame\Engine\GeneratedQuestion;
use App\SeriousGame\SeriousGamePlay;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Station Abysse played through the SDK's routes (design/validated/serious-games-abysse.md): a dive
 * descends 4 + 4 + 4 through the three depths, a food chain comes shuffled and its reversed reading
 * is a named error, and the ascent takes 3 + 3 + 2 + 2 + 2 observations from the five zones and
 * gives the Nautilus, its pin and the cards.
 */
class AbyssePlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'abysse.student');
        $this->game = $this->installGame('abysse');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/abysse');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/abysse/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testADiveDescendsThroughItsThreeDepths(): void
    {
        $session = self::int($this->call('POST', 'series', ['unit' => 'cladogramme']), 'session');
        $entity = static::getContainer()->get(SeriousGameSessionRepository::class)->find($session) ?? self::fail('session');

        self::assertSame([1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3], array_map(fn (array $planned): int => $this->replay($planned)->difficulty, $entity->getPlan()));
    }

    public function testAFoodChainComesShuffledAndIsCorrectedPlaceByPlace(): void
    {
        $this->acceptOnly(['order']);
        $session = self::int($this->call('POST', 'series', ['unit' => 'chaine']), 'session');
        $shown = self::arr($this->call('POST', 'sessions/'.$session.'/next'), 'question');
        $question = $this->replay($this->planned($session, 0));
        /** @var list<string> $order */
        $order = $question->answerValue;

        self::assertSame('order', $shown['answerType']);
        self::assertNotSame($order, array_column(self::arr($shown, 'choices'), 'value'));

        $result = $this->call('POST', 'sessions/'.$session.'/answer', ['input' => implode('|', array_reverse($order))]);
        self::assertFalse($result['ok']);
        self::assertSame($order, $result['expectedCells']);
        self::assertNotNull($result['misconception'], 'the chain read backwards is a named error');
    }

    public function testTheAscentTakesItsShareFromEachZoneAndGivesTheNautilus(): void
    {
        $this->catalogueEverySpecies();
        $final = $this->call('POST', 'final');
        self::assertSame(12, $final['size']);
        $session = static::getContainer()->get(SeriousGameSessionRepository::class)->find($final['session']) ?? self::fail('final');
        $manifest = SeriousGamePlay::manifest($this->game);
        $counts = array_values(array_count_values(array_map(static fn (array $planned): string => $manifest->unit($planned['unit'])['chapter'] ?? '', $session->getPlan())));
        sort($counts);
        self::assertSame([2, 2, 2, 3, 3], $counts);

        for ($i = 0; $i < 12; ++$i) {
            $this->call('POST', 'sessions/'.self::int($final, 'session').'/next');
            self::assertSame(['recorded' => true], $this->call('POST', 'sessions/'.self::int($final, 'session').'/answer', ['input' => $this->replay($session->getPlan()[$i])->answerText]));
        }
        $end = $this->call('POST', 'sessions/'.self::int($final, 'session').'/end');
        self::assertSame(12, $end['score']);
        $nautile = array_values(array_filter($end['rewards'], static fn (array $r): bool => 'nautile' === $r['code']))[0] ?? self::fail('nautile');
        self::assertSame('gold', $nautile['finish']);

        $held = array_column($this->call('GET', 'progress')['rewards'], 'code');
        sort($held);
        self::assertSame(['card-abysse-dorsale', 'card-abysse-fosse', 'card-abysse-plateau', 'card-abysse-recif', 'card-abysse-remontee', 'card-abysse-sources', 'nautile', 'pin-vague'], $held);
    }

    /** @param list<string> $kinds */
    private function acceptOnly(array $kinds): void
    {
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');
        $game->setAnswerKinds($kinds);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function catalogueEverySpecies(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->getReference(User::class, $this->student->getId());
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');
        foreach (SeriousGamePlay::manifest($game)->unitCodes() as $unit) {
            $state = new SeriousGameUnitState($player, $game, $unit);
            $state->recordSeries(9);
            $entityManager->persist($state);
        }
        $entityManager->flush();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function arr(array $data, string $key): array
    {
        self::assertIsArray($data[$key] ?? null, $key);

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        self::assertIsInt($data[$key] ?? null, $key);

        return $data[$key];
    }

    /** @return array{unit: string, version: int, seed: int} */
    private function planned(int $session, int $position): array
    {
        $entity = static::getContainer()->get(SeriousGameSessionRepository::class)->find($session);
        self::assertInstanceOf(SeriousGameSession::class, $entity);

        return $entity->plannedAt($position) ?? self::fail('no question '.$position);
    }

    /** @param array{unit: string, version: int, seed: int} $planned */
    private function replay(array $planned): GeneratedQuestion
    {
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');

        return static::getContainer()->get(SeriousGamePlay::class)->question($game, $planned);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = [], int $expected = 200): array
    {
        $this->client->request($method, '/serious-games/api/abysse/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($expected, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
