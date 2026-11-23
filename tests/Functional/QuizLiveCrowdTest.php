<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\QuizAnswer;
use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\LiveSessionStatus;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use App\Service\LiveAnswerTooLateException;
use App\Service\QuizLive\LiveResponse;
use App\Service\QuizLiveSessionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A full room - the report of 2026-11-23: at 31 players, answers were slow and, for some, « hard to
 * click ». Every answer queues on the session's lock, so what runs there must not grow with the room,
 * an answer is judged on when it *arrived* rather than when its turn came, the screens count a
 * question's time on the server's clock rather than their own, and a refused late answer says so.
 */
class QuizLiveCrowdTest extends FunctionalTestCase
{
    private User $teacher;
    private QuizTemplate $quiz;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'crowd.teacher');

        $this->quiz = new QuizTemplate($this->teacher);
        $this->quiz->setName('Réseaux');
        $this->quiz->setCreatedBy($this->teacher);
        foreach (['Port de HTTPS ?', 'Port de SSH ?'] as $rank => $label) {
            $question = new QuizQuestion($this->quiz);
            $question->setType(QuestionType::Qcm);
            $question->setLabel($label);
            $question->setTimeMode(QuestionTimeMode::Quiz);
            $question->setOrderIndex($rank + 1);
            foreach (['443' => 0 === $rank, '22' => 1 === $rank, '80' => false] as $text => $correct) {
                $answer = new QuizAnswer($question);
                $answer->setLabel((string) $text);
                $answer->setIsCorrect($correct);
                $answer->setOrderIndex($question->getAnswers()->count());
                $question->addAnswer($answer);
            }
            $this->quiz->addQuestion($question);
        }
        $this->em->persist($this->quiz);
        $this->em->flush();
    }

    public function testAnAnswerCostsTheSameQueriesWhateverTheSizeOfTheRoom(): void
    {
        self::assertSame($this->queriesOfAnAnswer(3), $this->queriesOfAnAnswer(25), 'the work under the session\'s lock does not grow with the room');
    }

    public function testAnAnswerIsJudgedOnWhenItArrivedNotOnWhenItsTurnCame(): void
    {
        $sessionId = $this->roomAtItsFirstQuestion(4);
        $session = $this->live($sessionId);
        $minuteAgo = new \DateTimeImmutable('-1 minute');
        $start = $minuteAgo->setTime(...$this->hms($minuteAgo));
        $session->setPhaseStartedAt($start);
        $this->em->flush();
        $right = $this->rightAnswerId($sessionId);

        // Arrived 5 s into a 20 s question, processed long after: it counts, at 5 s.
        $answer = $this->service()->submitAnswerFor($this->live($sessionId), $this->player($sessionId, 0), new LiveResponse([$right]), $start->modify('+5 seconds'));
        self::assertSame(875, $answer->getPointsAwarded());
        self::assertEquals($start->modify('+5 seconds'), $answer->getAnsweredAt());

        // The trip from the phone is forgiven, up to the grace.
        $grace = QuizLiveSessionService::ANSWER_GRACE_SECONDS;
        $late = $this->service()->submitAnswerFor($this->live($sessionId), $this->player($sessionId, 1), new LiveResponse([$right]), $start->modify(\sprintf('+%d seconds', 20 + $grace)));
        self::assertSame(500, $late->getPointsAwarded(), 'at the buzzer');

        $this->expectException(LiveAnswerTooLateException::class);
        $this->service()->submitAnswerFor($this->live($sessionId), $this->player($sessionId, 2), new LiveResponse([$right]), $start->modify(\sprintf('+%d seconds', 21 + $grace)));
    }

    /**
     * What the session's lock used to guarantee, held without it: an answer is one INSERT, so the
     * table's UNIQUE (participant, question) is what keeps a repeated POST from counting twice and
     * an answer from slipping in after the question closed.
     */
    public function testWithoutTheLockAnAnswerCountsOnceAndNeverAfterTheClose(): void
    {
        $sessionId = $this->roomAtItsFirstQuestion(3);
        $right = $this->rightAnswerId($sessionId);

        $first = $this->service()->submitAnswerFor($this->live($sessionId), $this->player($sessionId, 0), new LiveResponse([$right]));
        $again = $this->service()->submitAnswerFor($this->live($sessionId), $this->player($sessionId, 0), new LiveResponse([$right]));
        self::assertSame($first->getPointsAwarded(), $again->getPointsAwarded(), 'the first answer stands');
        $this->em->clear();
        self::assertSame($first->getPointsAwarded(), $this->player($sessionId, 0)->getScore(), 'counted once');

        // A request that read the question open, while the host closed it in the meantime.
        $session = $this->live($sessionId);
        $late = $this->player($sessionId, 1);
        $this->service()->advance($session, $this->teacher(), LiveSessionStatus::Question);
        self::assertSame(LiveSessionStatus::Results, $session->getStatus());
        $session->setStatus(LiveSessionStatus::Question); // what that request still believes
        try {
            $this->service()->submitAnswerFor($session, $late, new LiveResponse([$right]));
            self::fail('An answer after the close is refused.');
        } catch (LiveAnswerTooLateException) {
        }
        $this->em->clear();
        self::assertSame(0, $this->player($sessionId, 1)->getScore());

        // The phones are sent the ranking as compact standings and verdicts, the projector whole.
        $phone = $this->service()->buildPlayerSnapshot($this->live($sessionId));
        $board = $this->service()->buildHostSnapshot($this->live($sessionId));
        self::assertSame('results', $phone['type']);
        foreach (['leaderboard', 'outcomes', 'top', 'podium'] as $key) {
            self::assertArrayNotHasKey($key, $phone);
        }
        $winner = (int) $this->player($sessionId, 0)->getId();
        $refused = (int) $this->player($sessionId, 1)->getId();
        $points = $first->getPointsAwarded();
        self::assertIsArray($phone['standings'] ?? null);
        self::assertSame([$winner, $points, 'Joueur 0'], $phone['standings'][0], '[participantId, score, displayName], in rank order');
        self::assertCount(3, $phone['standings']);
        self::assertIsArray($phone['verdicts'] ?? null);
        self::assertSame([1, 1, $points], $phone['verdicts'][(string) $winner]);
        self::assertSame([0, 0, 0], $phone['verdicts'][(string) $refused]);
        self::assertIsArray($board['leaderboard'] ?? null);
        self::assertIsArray($board['leaderboard'][0]);
        self::assertArrayHasKey('avatar', $board['leaderboard'][0], 'the projector draws the faces');
        self::assertIsArray($board['outcomes'] ?? null);
    }

    public function testThePhoneIsToldItsAnswerCameTooLate(): void
    {
        $session = $this->service()->createSession([$this->quiz], null, $this->teacher, 20, null, true);
        $code = (string) $session->getRoomCode();
        $this->client->request('GET', '/live/'.$code);
        $this->client->request('POST', '/live/'.$code.'/guest', ['_token' => $this->csrfToken('quiz_live_guest'), 'nickname' => 'Retardataire', 'avatar' => 'goupil']);
        self::assertResponseRedirects('/live/'.$code.'/play');
        $this->toFirstQuestion((int) $session->getId());
        $this->live((int) $session->getId())->setPhaseStartedAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->client->request('POST', '/live/'.$code.'/answer', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-CSRF-Token' => $this->csrfToken('quiz_live_answer'),
        ], json_encode(['answerIds' => [$this->rightAnswerId((int) $session->getId())]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        $refusal = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($refusal);
        self::assertTrue($refusal['late'] ?? null, 'the phone says « Trop tard »');
    }

    public function testAQuestionStartsOnAWholeSecondNotBehindItsOpeningAndCarriesTheServersClock(): void
    {
        $sessionId = $this->roomAtItsFirstQuestion(2, $before);
        $start = $this->live($sessionId)->getPhaseStartedAt();
        self::assertNotNull($start);
        self::assertSame('000000', $start->format('u'), 'what the column keeps');
        self::assertGreaterThan($before, (float) $start->format('U'), 'rounded up: nobody loses the fraction');

        foreach ([$this->service()->buildPlayerSnapshot($this->live($sessionId)), $this->service()->buildHostSnapshot($this->live($sessionId))] as $state) {
            self::assertSame('question-opened', $state['type']);
            self::assertIsString($state['serverNow'] ?? null);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}[+-]\d\d:\d\d$/', $state['serverNow']);
            self::assertSame(QuizLiveSessionService::ANSWER_GRACE_SECONDS, $state['graceSeconds']);
        }
    }

    /** What the second answer of a fresh request costs, in statements sent to the database. */
    private function queriesOfAnAnswer(int $players): int
    {
        $sessionId = $this->roomAtItsFirstQuestion($players);
        $this->service()->submitAnswerFor($this->live($sessionId), $this->player($sessionId, 0), new LiveResponse([$this->rightAnswerId($sessionId)]));

        $this->em->clear(); // a request of its own
        $player = $this->player($sessionId, 1);
        $right = $this->rightAnswerId($sessionId);
        $session = $this->live($sessionId);

        $connection = $this->em->getConnection();
        $sent = static function () use ($connection): int {
            $value = $connection->fetchAssociative("SHOW SESSION STATUS LIKE 'Questions'")['Value'] ?? null;
            self::assertIsNumeric($value);

            return (int) $value;
        };
        $before = $sent();
        $this->service()->submitAnswerFor($session, $player, new LiveResponse([$right]));

        return $sent() - $before;
    }

    /** @param-out float $openedAt */
    private function roomAtItsFirstQuestion(int $players, ?float &$openedAt = null): int
    {
        // Fetched again: a measured answer clears the entity manager, as a request of its own would.
        $quiz = $this->em->find(QuizTemplate::class, $this->quiz->getId());
        self::assertInstanceOf(QuizTemplate::class, $quiz);
        $session = $this->service()->createSession([$quiz], null, $this->teacher(), 20, null, true);
        $sessionId = (int) $session->getId();
        for ($i = 0; $i < $players; ++$i) {
            $this->service()->joinGuest($this->live($sessionId), 'Joueur '.$i, 'goupil');
        }
        $openedAt = $this->toFirstQuestion($sessionId);

        return $sessionId;
    }

    /** @return float the moment the answers were shown */
    private function toFirstQuestion(int $sessionId): float
    {
        $this->service()->start($this->live($sessionId), $this->teacher());
        $this->service()->advance($this->live($sessionId), $this->teacher(), LiveSessionStatus::Countdown);
        $openedAt = microtime(true);
        $this->service()->advance($this->live($sessionId), $this->teacher(), LiveSessionStatus::Reading);
        self::assertSame(LiveSessionStatus::Question, $this->live($sessionId)->getStatus());

        return $openedAt;
    }

    private function teacher(): User
    {
        $teacher = $this->em->find(User::class, $this->teacher->getId());
        self::assertInstanceOf(User::class, $teacher);

        return $teacher;
    }

    private function service(): QuizLiveSessionService
    {
        return static::getContainer()->get(QuizLiveSessionService::class);
    }

    private function live(int $sessionId): QuizLiveSession
    {
        $session = $this->em->find(QuizLiveSession::class, $sessionId);
        self::assertInstanceOf(QuizLiveSession::class, $session);

        return $session;
    }

    private function player(int $sessionId, int $rank): QuizLiveParticipant
    {
        $player = $this->em->getRepository(QuizLiveParticipant::class)->findOneBy(['session' => $sessionId, 'displayName' => 'Joueur '.$rank]);
        self::assertInstanceOf(QuizLiveParticipant::class, $player);

        return $player;
    }

    private function rightAnswerId(int $sessionId): int
    {
        foreach ($this->live($sessionId)->getCurrentQuestion()?->getAnswers() ?? [] as $answer) {
            if ($answer->isCorrect()) {
                return (int) $answer->getId();
            }
        }
        self::fail('No right answer.');
    }

    /** @return array{int, int, int} */
    private function hms(\DateTimeImmutable $at): array
    {
        return [(int) $at->format('G'), (int) $at->format('i'), (int) $at->format('s')];
    }
}
