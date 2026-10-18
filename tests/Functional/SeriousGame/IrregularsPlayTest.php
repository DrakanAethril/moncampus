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
 * The Irregulars played through the SDK's routes (design/validated/serious-games-irregulars.md): a
 * line to correct sends its words and is corrected by the place pointed at, the words of a question
 * come shuffled and are corrected place by place, every case keeps questions when a kind is
 * unticked, and The Final Case takes 3 + 3 + 2 + 2 + 2
 * statements from the five places and gives the Loupe, its pin and the cards.
 */
class IrregularsPlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'irregulars.student');
        $this->game = $this->installGame('irregulars');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/irregulars');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/irregulars/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testALineToCorrectIsCorrectedByThePlacePointedAt(): void
    {
        $this->acceptOnly(['spot']);
        $session = self::int($this->call('POST', 'series', ['unit' => 'pret-neg']), 'session');
        $shown = self::arr($this->call('POST', 'sessions/'.$session.'/next'), 'question');
        $question = $this->replay($this->planned($session, 0));

        self::assertSame('spot', $shown['answerType']);
        self::assertSame($question->spec->labels, $shown['labels']);
        self::assertGreaterThan(2, \count($shown['labels']));
        self::assertArrayNotHasKey('answer', $shown);

        $result = $this->call('POST', 'sessions/'.$session.'/answer', ['input' => $question->answerText]);
        self::assertTrue($result['ok']);

        foreach (self::arr($this->call('GET', 'progress'), 'units') as $unit) {
            self::assertIsArray($unit);
            self::assertFalse($unit['hidden'] && 'pret-neg' === $unit['code']);
        }
    }

    public function testTheWordsOfAQuestionComeShuffledAndAreCorrectedPlaceByPlace(): void
    {
        $this->acceptOnly(['order']);
        $session = self::int($this->call('POST', 'series', ['unit' => 'question-order']), 'session');
        $shown = self::arr($this->call('POST', 'sessions/'.$session.'/next'), 'question');
        $question = $this->replay($this->planned($session, 0));
        /** @var list<string> $order */
        $order = $question->answerValue;
        $words = array_column(self::arr($shown, 'choices'), 'value');

        self::assertSame('order', $shown['answerType']);
        self::assertNotSame($order, $words, 'never in the order of the answer');
        $sorted = $order;
        sort($sorted);
        sort($words);
        self::assertSame($sorted, $words);

        $swapped = $order;
        [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];
        $result = $this->call('POST', 'sessions/'.$session.'/answer', ['input' => implode('|', $swapped)]);
        self::assertFalse($result['ok']);
        self::assertSame(array_merge([false, false], array_fill(0, \count($order) - 2, true)), $result['cells']);
        self::assertSame($order, $result['expectedCells']);
    }

    public function testAGameWithoutSpotHidesNothingAndServesTheChoices(): void
    {
        $this->acceptOnly(['short', 'choice']);
        $progress = $this->call('GET', 'progress');
        self::assertSame([], array_column(array_filter(self::arr($progress, 'units'), static fn (mixed $u): bool => \is_array($u) && true === $u['hidden']), 'code'), 'every case keeps a QCM');

        $series = $this->call('POST', 'series', ['unit' => 'if-clause']);
        $session = static::getContainer()->get(SeriousGameSessionRepository::class)->find($series['session']) ?? self::fail('session');
        foreach ($session->getPlan() as $planned) {
            self::assertSame('choice', $this->replay($planned)->kind);
        }
    }

    public function testTheFinalCaseTakesItsShareFromEachPlaceAndGivesTheLoupe(): void
    {
        $this->solveEveryCase();
        $final = $this->call('POST', 'final');
        self::assertSame(12, $final['size']);
        $session = static::getContainer()->get(SeriousGameSessionRepository::class)->find($final['session']) ?? self::fail('final');
        $manifest = SeriousGamePlay::manifest($this->game);
        $places = array_count_values(array_map(static fn (array $planned): string => $manifest->unit($planned['unit'])['chapter'] ?? '', $session->getPlan()));
        $counts = array_values($places);
        sort($counts);
        self::assertSame([2, 2, 2, 3, 3], $counts);

        for ($i = 0; $i < 12; ++$i) {
            $this->call('POST', 'sessions/'.self::int($final, 'session').'/next');
            self::assertSame(['recorded' => true], $this->call('POST', 'sessions/'.self::int($final, 'session').'/answer', ['input' => $this->replay($session->getPlan()[$i])->answerText]));
        }
        $end = $this->call('POST', 'sessions/'.self::int($final, 'session').'/end');
        self::assertSame(12, $end['score']);
        $loupe = array_values(array_filter($end['rewards'], static fn (array $r): bool => 'loupe' === $r['code']))[0] ?? self::fail('loupe');
        self::assertSame('gold', $loupe['finish']);

        $held = array_column($this->call('GET', 'progress')['rewards'], 'code');
        sort($held);
        self::assertSame(['card-irregulars-docks', 'card-irregulars-final-case', 'card-irregulars-market', 'card-irregulars-museum', 'card-irregulars-station', 'card-irregulars-theatre', 'loupe', 'pin-loupe'], $held);
    }

    /** @param list<string> $kinds */
    private function acceptOnly(array $kinds): void
    {
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');
        $game->setAnswerKinds($kinds);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function solveEveryCase(): void
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
        $this->client->request($method, '/serious-games/api/irregulars/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($expected, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
