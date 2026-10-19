<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\Accommodation;
use App\Entity\SeriousGame;
use App\Entity\SeriousGameSession;
use App\Entity\SeriousGameUnitState;
use App\Entity\User;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameSessionRepository;
use App\SeriousGame\Engine\AnswerCorrector;
use App\SeriousGame\SeriousGamePlay;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'Observatoire played through the SDK's routes (design/validated/serious-games.md §6), the way
 * the host page relays them: a series lights a star, a worse one never dims it, a typical error
 * made twice raises a comet that is then studied, « je ne sais pas » names no error, and the
 * Couronne opens on 31 stars with the accommodations' extra time - and says nothing before its end.
 */
class SeriousGamePlayTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    private User $student;
    private SeriousGame $game;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'play.student');
        $this->game = $this->installObservatoire();
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/serious-games/play/observatoire');
        self::assertResponseIsSuccessful();
        $this->token = $this->csrfToken('serious-games');
    }

    public function testTheHostPageCarriesTheFullScreenToggleAndLoadsTheGameOnceItListens(): void
    {
        $this->client->request('GET', '/serious-games/play/observatoire');
        $page = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-action="serious-game-host#toggleFullscreen"', $page);
        self::assertStringContainsString('data-serious-game-host-target="timer"', $page);
        self::assertStringContainsString('data-serious-game-host-src-value="/serious-games/observatoire/index.html', $page);
        self::assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/', $page, 'the relay gives the frame its address');
    }

    public function testATimedSessionCarriesItsDeadlineAndTheServersClock(): void
    {
        $this->lightEveryStar();
        $final = $this->call('POST', 'final');

        self::assertIsString($final['deadline']);
        self::assertIsString($final['serverTime']);
        self::assertSame(1200, strtotime($final['deadline']) - strtotime($final['serverTime']));
        $next = $this->call('POST', 'sessions/'.self::int($final, 'session').'/next');
        self::assertSame($final['deadline'], $next['deadline']);
    }

    public function testASeriesLightsAStarAndAWorseOneNeverDimsIt(): void
    {
        $series = $this->call('POST', 'series', ['unit' => 'taux']);
        self::assertSame(12, $series['size']);

        $this->play(self::int($series, 'session'), 12, function (array $question, array $planned): string {
            self::assertArrayNotHasKey('answer', $question, 'the game never receives the answer');

            return $this->answerOf($planned);
        });
        $end = $this->call('POST', 'sessions/'.self::int($series, 'session').'/end');
        self::assertSame(12, $end['score']);
        self::assertTrue($end['newlyLit']);
        self::assertSame('blazing', $end['brightness']);

        $second = $this->call('POST', 'series', ['unit' => 'taux']);
        $this->play(self::int($second, 'session'), 12, static fn (): string => AnswerCorrector::DONT_KNOW);
        $end = $this->call('POST', 'sessions/'.self::int($second, 'session').'/end');
        self::assertSame(0, $end['score']);
        self::assertSame(12, $end['best']);
        self::assertSame('blazing', $end['brightness']);
        self::assertSame([], $end['comets'], '« je ne sais pas » names no error');

        $progress = $this->call('GET', 'progress');
        $taux = array_values(array_filter($progress['units'], static fn (array $u): bool => 'taux' === $u['code']))[0];
        self::assertTrue($taux['lit']);
        self::assertSame(2, $taux['series']);
        self::assertSame(1, $progress['lit']);
    }

    public function testAnAnswerIsCorrectedWithItsMethodAndItsError(): void
    {
        $series = $this->call('POST', 'series', ['unit' => 'taux']);
        $next = $this->call('POST', 'sessions/'.self::int($series, 'session').'/next');
        $question = $this->replay(self::int($series, 'session'), 0);

        $wrong = $question->wrongs[0];
        $result = $this->call('POST', 'sessions/'.self::int($series, 'session').'/answer', ['input' => $wrong['text']]);

        self::assertSame(0, $next['position']);
        self::assertFalse($result['ok']);
        self::assertSame($wrong['misconception'], $result['misconception']);
        self::assertNotEmpty($result['method']);
        self::assertNotEmpty($result['expected']);

        $this->call('POST', 'sessions/'.self::int($series, 'session').'/next');
        $unreadable = $this->call('POST', 'sessions/'.self::int($series, 'session').'/answer', ['input' => 'trente']);
        self::assertFalse($unreadable['recorded'], 'a text that does not read is written again');
    }

    public function testAnErrorMadeTwiceRaisesACometThatIsThenStudied(): void
    {
        $series = $this->call('POST', 'series', ['unit' => 'taux']);
        $errors = 0;
        $this->play(self::int($series, 'session'), 12, function (array $question, array $planned) use (&$errors): string {
            $generated = $this->replay(0, 0, $planned);
            foreach ($generated->wrongs as $wrong) {
                if ('taux-arrival' === $wrong['misconception'] && $errors < 2) {
                    ++$errors;

                    return $wrong['text'];
                }
            }

            return $this->answerOf($planned);
        });
        $end = $this->call('POST', 'sessions/'.self::int($series, 'session').'/end');
        self::assertSame(['taux-arrival'], array_column($end['comets'], 'misconception'));
        self::assertSame(10, $end['score']);

        $study = $this->call('POST', 'comets', ['misconception' => 'taux-arrival']);
        self::assertSame(3, $study['size']);
        self::assertStringStartsWith('L’erreur qui revient', $study['explanation']);
        $this->play(self::int($study, 'session'), 3, fn (array $question, array $planned): string => $this->answerOf($planned));
        $end = $this->call('POST', 'sessions/'.self::int($study, 'session').'/end');
        self::assertTrue($end['solved']);
        $progress = $this->call('GET', 'progress');
        self::assertSame([], $progress['comets'], 'élucidée, the comet leaves the map');

        // ... and enters the bestiary, with what the study said of it
        $bestiary = $progress['bestiary'];
        self::assertIsArray($bestiary);
        self::assertSame(['taux-arrival'], array_column($bestiary, 'misconception'));
        $entry = $bestiary[0];
        self::assertIsArray($entry);
        self::assertSame('taux', $entry['unit']);
        self::assertIsString($entry['explanation']);
        self::assertStringStartsWith('L’erreur qui revient', $entry['explanation']);
        self::assertGreaterThan(100, $progress['bestiaryTotal'], 'every typical error of the servable stars');
    }

    public function testTheCouronneOpensOnThirtyOneStarsWithTheExtraTime(): void
    {
        self::assertSame('final-locked', $this->call('POST', 'final', [], 409)['error']);

        $this->lightEveryStar();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $accommodation = (new Accommodation('Tiers-temps'))->setQuizExtraTimePercent('33.33');
        $accommodation->setCreatedBy($entityManager->getReference(User::class, $this->student->getId()));
        $entityManager->persist($accommodation);
        $entityManager->getReference(User::class, $this->student->getId())->addAccommodation($accommodation);
        $entityManager->flush();

        $final = $this->call('POST', 'final');
        self::assertSame(12, $final['size']);
        self::assertSame(1600, $final['timeLimitSeconds']);

        $session = static::getContainer()->get(SeriousGameSessionRepository::class)->find($final['session']) ?? self::fail('final');
        $units = array_column($session->getPlan(), 'unit');
        self::assertCount(12, array_unique($units));

        $this->call('POST', 'sessions/'.self::int($final, 'session').'/next');
        $answer = $this->call('POST', 'sessions/'.self::int($final, 'session').'/answer', ['input' => AnswerCorrector::DONT_KNOW]);
        self::assertSame(['recorded' => true], $answer, 'nothing before the end');

        $end = $this->call('POST', 'sessions/'.self::int($final, 'session').'/end');
        self::assertTrue($end['timedOut']);
        self::assertCount(12, $end['review']);
        self::assertContains('astrolabe', array_column($this->call('GET', 'progress')['rewards'], 'code'));
    }

    private function lightEveryStar(): void
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
     * Plays the questions of a session, answering each with what $answer returns.
     *
     * @param \Closure(array<string, mixed>, array{unit: string, version: int, seed: int}): string $answer
     */
    private function play(int $session, int $count, \Closure $answer): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $next = $this->call('POST', 'sessions/'.$session.'/next');
            self::assertFalse($next['done']);
            $planned = $this->planned($session, self::int($next, 'position'));
            $result = $this->call('POST', 'sessions/'.$session.'/answer', ['input' => $answer($next['question'], $planned)]);
            self::assertTrue($result['recorded'], json_encode($result, \JSON_THROW_ON_ERROR));
        }
        self::assertTrue($this->call('POST', 'sessions/'.$session.'/next')['done']);
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
    private function answerOf(array $planned): string
    {
        return $this->replay(0, 0, $planned)->answerText;
    }

    /** @param array{unit: string, version: int, seed: int}|null $planned */
    private function replay(int $session, int $position, ?array $planned = null): \App\SeriousGame\Engine\GeneratedQuestion
    {
        $planned ??= $this->planned($session, $position);
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
        $this->client->request($method, '/serious-games/api/observatoire/'.$path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], 'GET' === $method ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($expected, (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
