<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameSession;
use App\Entity\SeriousGameUnitState;
use App\Entity\User;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameSessionRepository;
use App\SeriousGame\Engine\AnswerCorrector;
use App\SeriousGame\Engine\GeneratedQuestion;
use App\SeriousGame\SeriousGamePlay;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les Tablettes de Pompéi played through the SDK's routes (design/validated/serious-games-pompei.md):
 * a QCM sends its propositions and never says which one is right before the answer, a table to fill
 * is corrected cell by cell, a game that accepts no QCM hides the units that only ask QCM and never
 * serves a QCM of a mixed one, and the Lararium takes two inscriptions from each room and gives the
 * Groma, its pin and the cards.
 */
class PompeiPlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'pompei.student');
        $this->game = $this->installGame('pompei');
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/pompei');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/pompei/index.html', (string) $this->client->getResponse()->getContent());
        $this->token = $this->csrfToken('serious-games');
    }

    public function testAQcmSendsItsPropositionsAndNamesTheErrorChosen(): void
    {
        $series = $this->call('POST', 'series', ['unit' => 'declinaison']);
        $session = self::int($series, 'session');
        $next = $this->call('POST', 'sessions/'.$session.'/next');
        $question = $this->replay($this->planned($session, 0));
        $shown = self::arr($next, 'question');
        $choices = self::arr($shown, 'choices');

        self::assertSame('choice', $shown['kind']);
        self::assertCount(4, $choices);
        self::assertArrayNotHasKey('answer', $shown);
        self::assertSame(array_column($question->choices, 'value'), array_column($choices, 'value'));
        self::assertContains($question->answerText, array_column($choices, 'value'));

        $wrong = $question->wrongs[0];
        $result = $this->call('POST', 'sessions/'.$session.'/answer', ['input' => $wrong['text']]);
        self::assertFalse($result['ok']);
        self::assertSame($wrong['misconception'], $result['misconception']);
        self::assertSame($question->answerText, $result['expectedText'], 'the right proposition is told after the answer');
    }

    public function testATableIsCorrectedCellByCell(): void
    {
        $series = $this->call('POST', 'series', ['unit' => 'tableau']);
        $session = self::int($series, 'session');
        $shown = self::arr($this->call('POST', 'sessions/'.$session.'/next'), 'question');
        self::assertSame('forms', $shown['answerType']);
        self::assertSame(['Nominatif', 'Vocatif', 'Accusatif', 'Génitif', 'Datif', 'Ablatif'], $shown['labels']);

        $cells = explode(' · ', $this->replay($this->planned($session, 0))->answerText);
        $cells[4] = 'rosum';
        $result = $this->call('POST', 'sessions/'.$session.'/answer', ['input' => implode('|', $cells)]);

        self::assertFalse($result['ok']);
        self::assertSame([true, true, true, true, false, true], $result['cells']);
        $expected = self::arr($result, 'expectedCells');
        self::assertCount(6, $expected);
        self::assertNotSame('rosum', $expected[4] ?? null);
    }

    public function testAGameWithoutQcmHidesWhatOnlyAsksQcmAndServesTheRestTyped(): void
    {
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($this->game->getId()) ?? self::fail('game');
        $game->setAnswerKinds(['short']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $progress = $this->call('GET', 'progress');
        $hidden = array_column(array_filter($progress['units'], static fn (array $u): bool => $u['hidden']), 'code');
        sort($hidden);
        self::assertSame(['analyse-1-2', 'analyse-3', 'cas', 'declinaison', 'fonction'], $hidden);
        self::assertSame(23, $progress['total']);
        $this->call('POST', 'series', ['unit' => 'fonction'], 409);

        $series = $this->call('POST', 'series', ['unit' => 'accord']);
        $session = static::getContainer()->get(SeriousGameSessionRepository::class)->find($series['session']) ?? self::fail('session');
        foreach ($session->getPlan() as $planned) {
            self::assertSame('short', $this->replay($planned)->kind, 'a game that accepts no QCM is never served one');
        }
    }

    public function testTheLarariumTakesTwoInscriptionsFromEachRoomAndGivesTheGroma(): void
    {
        $this->traceEveryTablet();
        $final = self::arr($this->call('GET', 'progress'), 'final');
        self::assertTrue($final['unlocked']);
        self::assertSame('Le Lararium', $final['label']);

        $final = $this->call('POST', 'final');
        self::assertSame(12, $final['size']);
        $session = static::getContainer()->get(SeriousGameSessionRepository::class)->find($final['session']) ?? self::fail('final');
        $manifest = SeriousGamePlay::manifest($this->game);
        $rooms = array_count_values(array_map(static fn (array $planned): string => $manifest->unit($planned['unit'])['chapter'] ?? '', $session->getPlan()));
        ksort($rooms);
        self::assertSame(['atrium' => 2, 'hortus' => 2, 'peristylium' => 2, 'tablinum' => 2, 'thermae' => 2, 'triclinium' => 2], $rooms);

        for ($i = 0; $i < 12; ++$i) {
            $this->call('POST', 'sessions/'.self::int($final, 'session').'/next');
            $answer = $this->call('POST', 'sessions/'.self::int($final, 'session').'/answer', ['input' => $this->inputOf($this->replay($session->getPlan()[$i]))]);
            self::assertSame(['recorded' => true], $answer, 'nothing before the end');
        }
        $end = $this->call('POST', 'sessions/'.self::int($final, 'session').'/end');
        self::assertSame(12, $end['score']);
        self::assertSame(['card-pompei-lararium', 'groma'], array_values(array_intersect(['card-pompei-lararium', 'groma'], array_column($end['rewards'], 'code'))));
        $groma = array_values(array_filter($end['rewards'], static fn (array $r): bool => 'groma' === $r['code']))[0];
        self::assertSame('gold', $groma['finish']);

        $held = array_column($this->call('GET', 'progress')['rewards'], 'code');
        sort($held);
        self::assertSame(['card-pompei-atrium', 'card-pompei-hortus', 'card-pompei-lararium', 'card-pompei-peristylium', 'card-pompei-tablinum', 'card-pompei-thermae', 'card-pompei-triclinium', 'groma', 'pin-cave-canem'], $held);
    }

    public function testDontKnowNamesNoError(): void
    {
        $series = $this->call('POST', 'series', ['unit' => 'rosa']);
        $this->call('POST', 'sessions/'.self::int($series, 'session').'/next');
        $result = $this->call('POST', 'sessions/'.self::int($series, 'session').'/answer', ['input' => AnswerCorrector::DONT_KNOW]);

        self::assertTrue($result['dontKnow']);
        self::assertNull($result['misconception']);
        self::assertNotEmpty($result['method']);
    }

    private function traceEveryTablet(): void
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

    /** What the student types or chooses for the right answer - a table's cells joined as the game joins them. */
    private function inputOf(GeneratedQuestion $question): string
    {
        return 'forms' === $question->spec->type->value ? str_replace(' · ', '|', $question->answerText) : $question->answerText;
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
        $this->client->request($method, '/serious-games/api/pompei/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($expected, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
