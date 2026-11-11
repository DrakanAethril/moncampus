<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\QuizAnswer;
use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizLiveAnswer;
use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\LiveSessionStatus;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use App\Enum\ToleranceMode;
use App\Service\QuizLiveSessionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A live concours as Kahoot runs one (the user's decision of 2026-11-05): every type a Kahoot carries,
 * the statement alone before the answers, a question closed by the last answer, the results and each
 * phone's verdict, the scoreboard - skipped after a vote -, the podium. The answers go through the
 * phones' own route, the contest's public address (/live/{code}); the rules are pinned on primitives in
 * tests/Service/QuizLive/. Since 2026-11-07 the phone is told the statement and the propositions too
 * (design/validated/quiz-live-public.md): a public contest may be played far from the projector.
 */
class QuizLiveSessionTest extends FunctionalTestCase
{
    private User $teacher;
    private User $alice;
    private User $bruno;
    private Program $program;
    private QuizTemplate $quiz;
    private EntityManagerInterface $em;
    private int $sessionId = 0;
    private string $code = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'live.teacher');
        $this->alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'live.alice');
        $this->bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'live.bruno');
        $this->program = $this->createProgram([$this->alice, $this->bruno], [$this->teacher]);

        $this->quiz = new QuizTemplate($this->teacher);
        $this->quiz->setName('CEJM - Révisions');
        $this->quiz->setCreatedBy($this->teacher);
        $this->question(QuestionType::Qcm, 'Le SI est', ['un ensemble de ressources' => true, 'un logiciel' => false]);
        $this->question(QuestionType::QcmMulti, 'Mécanismes de coordination', ['Ajustement mutuel' => true, 'Supervision indirecte' => false, 'Standardisation des procédés' => true, 'Standardisation des objectifs' => false, 'Standardisation des compétences' => false, 'Standardisation des normes' => false]);
        $this->question(QuestionType::Ordre, 'Classez les sources du droit', ['Droit communautaire' => false, 'Code du Travail' => false, 'Contrat de travail' => false]);
        $number = $this->question(QuestionType::Numerique, 'Le salaire net moyen', []);
        $number->setNumericConfig(['answer' => 2733, 'tolerance' => 400, 'toleranceMode' => ToleranceMode::Absolute->value, 'decimals' => 0, 'range' => ['min' => 0, 'max' => 4000, 'step' => 1]]);
        $this->question(QuestionType::Vote, 'Je vote pour le projet', ['Cendrier' => false, 'Jeu de cartes' => false]);
        $this->question(QuestionType::TexteATrous, 'Une analyse ... du macro-environnement', [])->setBlankAnswers([['PESTEL']]);
        $typed = $this->question(QuestionType::ReponseCourte, 'Pour le macro-environnement, l\'analyse', []);
        $typed->setBlankAnswers([['PESTEL']]);
        $this->em->persist($this->quiz);
        $this->em->flush();
    }

    public function testAWholeGameRunsLikeKahoot(): void
    {
        $service = $this->service();
        self::assertSame(['Une analyse ... du macro-environnement'], $service->skippedQuestions([$this->quiz]), 'a texte à trous is left out, and named');

        $session = $service->createSession([$this->quiz], $this->program, $this->teacher, 20);
        self::assertCount(6, $session->getQuizInstance()->getQuestions());
        $this->sessionId = (int) $session->getId();
        $this->code = (string) $session->getRoomCode();
        $service->join($session, $this->alice, 'Alice');
        $service->join($session, $this->bruno, 'Bruno');
        $service->start($session, $this->teacher);

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', \sprintf('/quiz-live/%d/projector', $this->sessionId));
        self::assertResponseIsSuccessful();

        $this->advance(LiveSessionStatus::Countdown);

        // 1. QCM - the statement alone first, on the board and on the phones.
        self::assertSame(LiveSessionStatus::Reading, $this->live()->getStatus());
        self::assertSame('Le SI est', $service->buildHostSnapshot($this->live())['label']);
        self::assertSame('Le SI est', $service->buildPlayerSnapshot($this->live())['label']);
        $this->advance(LiveSessionStatus::Reading);
        self::assertSame(LiveSessionStatus::Question, $this->live()->getStatus());
        $opened = $service->buildPlayerSnapshot($this->live());
        self::assertSame('choice', $opened['kind']);
        self::assertIsArray($opened['answers']);
        $first = $opened['answers'][0];
        self::assertIsArray($first);
        self::assertSame('un ensemble de ressources', $first['label'], 'a phone reads the propositions');
        self::assertArrayNotHasKey('correct', $first, 'never which one is right');

        $this->answer($this->alice, ['answerIds' => [$this->right()[0]]]);
        self::assertSame(LiveSessionStatus::Question, $this->live()->getStatus(), 'Bruno has not answered yet');
        self::assertTrue($service->buildPlayerSnapshot($this->live(), $this->participant($this->alice))['answered'] ?? false, 'a reloaded phone is not offered the shapes again');
        self::assertFalse($service->buildPlayerSnapshot($this->live(), $this->participant($this->bruno))['answered'] ?? true);
        $this->answer($this->bruno, ['answerIds' => [$this->wrong()[0]]]);
        self::assertSame(LiveSessionStatus::Results, $this->live()->getStatus(), 'the last answer closes the question');

        $results = $service->buildPlayerSnapshot($this->live());
        self::assertSame('results', $results['type']);
        self::assertSame([1, 1], array_column($this->rows($results), 'count'));
        $alice = $this->outcome($results, $this->alice);
        self::assertTrue($alice['correct']);
        self::assertGreaterThanOrEqual(500, $alice['points']);
        self::assertSame(['answered' => true, 'correct' => false, 'points' => 0], $this->outcome($results, $this->bruno));

        // A click and the clock arriving together move the room once.
        $service->advance($this->live(), $this->teacher, LiveSessionStatus::Question, 0);
        self::assertSame(LiveSessionStatus::Results, $this->live()->getStatus());

        $this->advance(LiveSessionStatus::Results);
        self::assertSame(LiveSessionStatus::Scoreboard, $this->live()->getStatus());
        self::assertSame('Alice', $this->firstName($service->buildHostSnapshot($this->live()), 'top'));
        $this->advance(LiveSessionStatus::Scoreboard);
        $this->advance(LiveSessionStatus::Reading);

        // 2. QCM multiple, six shapes: all its right propositions and none other.
        self::assertCount(6, $service->buildPlayerSnapshot($this->live())['answers']);
        $this->answer($this->alice, ['answerIds' => [99999]], 409);
        $this->answer($this->alice, ['answerIds' => $this->right()]);
        $this->answer($this->bruno, ['answerIds' => [$this->right()[0]]]);
        $results = $service->buildHostSnapshot($this->live());
        self::assertTrue($this->outcome($results, $this->alice)['correct']);
        self::assertFalse($this->outcome($results, $this->bruno)['correct'], 'no partial points');
        $this->next();

        // 3. Remise en ordre: shown shuffled, answered whole.
        $rightOrder = array_map(static fn (QuizInstanceAnswer $answer): int => (int) $answer->getId(), $this->current()->getAnswers()->toArray());
        $shown = array_column($service->buildPlayerSnapshot($this->live())['answers'], 'answerId');
        self::assertNotSame($rightOrder, $shown, 'shown in the right order, it would spell the answer');
        $this->answer($this->alice, ['order' => \array_slice($rightOrder, 0, 2)], 409);
        $this->answer($this->alice, ['order' => $rightOrder]);
        $this->answer($this->bruno, ['order' => array_reverse($rightOrder)]);
        self::assertTrue($this->outcome($service->buildHostSnapshot($this->live()), $this->alice)['correct']);
        $this->next();

        // 4. Numérique on a slider, inside the margin or not.
        $opened = $service->buildPlayerSnapshot($this->live());
        self::assertSame(['number', ['min' => 0.0, 'max' => 4000.0, 'step' => 1.0]], [$opened['kind'], $opened['range']]);
        $this->answer($this->alice, ['value' => 2500]);
        $this->answer($this->bruno, ['value' => 3500]);
        $results = $service->buildHostSnapshot($this->live());
        self::assertTrue($this->outcome($results, $this->alice)['correct']);
        self::assertFalse($this->outcome($results, $this->bruno)['correct']);
        self::assertSame('2 733 ± 400', $results['expected']);
        $this->next();

        // 5. Vote: counted, never marked - and no scoreboard after it.
        $first = (int) $this->current()->getAnswers()->first()->getId();
        $this->answer($this->alice, ['answerIds' => [$first]]);
        $this->answer($this->bruno, ['answerIds' => [$first]]);
        $results = $service->buildHostSnapshot($this->live());
        self::assertTrue($results['isVote']);
        self::assertSame(['answered' => true, 'correct' => null, 'points' => 0], $this->outcome($results, $this->alice));
        self::assertSame([2, 0], array_column($this->rows($results), 'count'));
        $this->advance(LiveSessionStatus::Results);
        self::assertSame(LiveSessionStatus::Reading, $this->live()->getStatus(), 'a vote moved nobody\'s score');
        $this->advance(LiveSessionStatus::Reading);

        // 6. Réponse courte, typed - after the clock, nothing is taken any more.
        $this->answer($this->alice, ['text' => 'pestel']);
        $this->live()->setPhaseStartedAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();
        $this->answer($this->bruno, ['text' => 'PESTEL'], 409);
        $this->advance(LiveSessionStatus::Question);
        $results = $service->buildHostSnapshot($this->live());
        self::assertTrue($this->outcome($results, $this->alice)['correct']);
        self::assertSame(['answered' => false, 'correct' => false, 'points' => 0], $this->outcome($results, $this->bruno));
        self::assertTrue($results['isLastQuestion']);

        // After the last question, the podium - no scoreboard in between.
        $this->advance(LiveSessionStatus::Results);
        self::assertSame(LiveSessionStatus::Finished, $this->live()->getStatus());
        self::assertSame('Alice', $this->firstName($service->buildPlayerSnapshot($this->live()), 'podium'));
        self::assertCount(12, $this->em->getRepository(QuizLiveAnswer::class)->findBy(['participant' => $this->live()->getParticipants()->toArray()]));
    }

    /**
     * A phone back from the background asks for the phase again - the installed Formatool above all, whose stream
     * misses what was published while it slept. It is told whether it already answered, and a
     * classmate's answer tells it nothing.
     */
    public function testAPhoneBackFromTheBackgroundIsToldWhereTheRoomIs(): void
    {
        $service = $this->service();
        $session = $service->createSession([$this->quiz], $this->program, $this->teacher, 20);
        $this->sessionId = (int) $session->getId();
        $this->code = (string) $session->getRoomCode();
        $service->join($session, $this->alice, 'Alice');
        $service->join($session, $this->bruno, 'Bruno');
        $service->start($session, $this->teacher);
        $this->advance(LiveSessionStatus::Countdown);
        $this->advance(LiveSessionStatus::Reading);

        $this->answer($this->alice, ['answerIds' => [$this->right()[0]]]);

        foreach ([[$this->alice, true], [$this->bruno, false]] as [$student, $answered]) {
            $this->client->loginUser($student);
            $this->client->request('GET', \sprintf('/live/%s/state', $this->code));
            self::assertResponseIsSuccessful();
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertIsArray($body);
            self::assertIsArray($body['state']);
            self::assertSame(['question-opened', $answered], [$body['state']['type'], $body['state']['answered'] ?? null]);
            self::assertArrayNotHasKey('outcomes', $body['state'], 'nobody\'s verdict before the question closes');
        }

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', \sprintf('/quiz-live/%d/projector/state', $this->sessionId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Le SI est', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, bool> $answers
     */
    private function question(QuestionType $type, string $label, array $answers): QuizQuestion
    {
        $question = new QuizQuestion($this->quiz);
        $question->setType($type);
        $question->setLabel($label);
        $question->setTimeMode(QuestionTimeMode::Quiz);
        $question->setOrderIndex($this->quiz->getQuestions()->count() + 1);
        $index = 0;
        foreach ($answers as $text => $correct) {
            $answer = new QuizAnswer($question);
            $answer->setLabel($text);
            $answer->setIsCorrect($correct);
            $answer->setOrderIndex($index++);
            $question->addAnswer($answer);
        }
        $this->quiz->addQuestion($question);

        return $question;
    }

    private function service(): QuizLiveSessionService
    {
        return static::getContainer()->get(QuizLiveSessionService::class);
    }

    /**
     * The session as the database has it now: every request through the browser clears the entity
     * manager, so an object kept across one would be detached.
     */
    private function live(): QuizLiveSession
    {
        $session = $this->em->find(QuizLiveSession::class, $this->sessionId);
        self::assertInstanceOf(QuizLiveSession::class, $session);

        return $session;
    }

    private function participant(User $student): QuizLiveParticipant
    {
        $participant = $this->em->getRepository(QuizLiveParticipant::class)->findOneBy(['session' => $this->live(), 'student' => $student]);
        self::assertInstanceOf(QuizLiveParticipant::class, $participant);

        return $participant;
    }

    private function advance(LiveSessionStatus $from): void
    {
        $session = $this->live();
        $this->service()->advance($session, $this->teacher, $from, $session->getCurrentQuestionIndex());
    }

    /** Results -> scoreboard -> next statement -> its answers. */
    private function next(): void
    {
        $this->advance(LiveSessionStatus::Results);
        $this->advance(LiveSessionStatus::Scoreboard);
        $this->advance(LiveSessionStatus::Reading);
        self::assertSame(LiveSessionStatus::Question, $this->live()->getStatus());
    }

    /** @param array<string, mixed> $body */
    private function answer(User $student, array $body, int $expected = 200): void
    {
        $this->client->loginUser($student);
        $this->client->request('GET', \sprintf('/live/%s/play', $this->code));
        self::assertResponseIsSuccessful();
        $this->client->request('POST', \sprintf('/live/%s/answer', $this->code), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-CSRF-Token' => $this->csrfToken('quiz_live_answer'),
        ], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expected);
    }

    private function current(): QuizInstanceQuestion
    {
        return $this->live()->getCurrentQuestion() ?? throw new \LogicException('No current question.');
    }

    /** @return list<int> the right propositions of the current question */
    private function right(): array
    {
        return $this->idsWhere(true);
    }

    /** @return list<int> */
    private function wrong(): array
    {
        return $this->idsWhere(false);
    }

    /** @return list<int> */
    private function idsWhere(bool $correct): array
    {
        return array_values(array_map(
            static fn (QuizInstanceAnswer $answer): int => (int) $answer->getId(),
            array_filter($this->current()->getAnswers()->toArray(), static fn (QuizInstanceAnswer $answer): bool => $answer->isCorrect() === $correct),
        ));
    }

    /** @param array<string, mixed> $payload */
    private function firstName(array $payload, string $key): mixed
    {
        $rows = $payload[$key] ?? null;
        self::assertIsArray($rows);
        $first = $rows[0] ?? null;
        self::assertIsArray($first);

        return $first['displayName'] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload): array
    {
        self::assertIsArray($payload['rows'] ?? null);

        return array_values($payload['rows']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{answered: bool, correct: bool|null, points: int}
     */
    private function outcome(array $payload, User $student): array
    {
        $outcomes = $payload['outcomes'] ?? null;
        self::assertIsArray($outcomes);
        $outcome = $outcomes[(string) $this->participant($student)->getId()] ?? null;
        self::assertIsArray($outcome);

        /* @var array{answered: bool, correct: bool|null, points: int} $outcome */
        return $outcome;
    }
}
