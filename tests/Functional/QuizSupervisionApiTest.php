<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizMode;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * « Un client qui ne sait pas rapporter ne compose pas », proved without the mobile app.
 *
 * A supervised mode applied to the web alone cancels itself the moment the phone is opened - and
 * turns against the school, since the student composing honestly on a computer is then the only one
 * being measured. The refusal is therefore the server's, not a padlock drawn on a card: an app
 * recompiled without the padlock meets exactly the same 409.
 *
 * And the capability is *declared* rather than deduced from a version number: what was shipped is
 * not what is compiled in. A third-party client that declares nothing is treated like the old app.
 *
 * A real LexikJWT token, because `/api` is a stateless firewall - a session login proves nothing
 * about that door.
 */
class QuizSupervisionApiTest extends FunctionalTestCase
{
    private User $student;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'supervision.student');
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'supervision.teacher');
        $this->program = $this->createProgram([$this->student], [$teacher]);

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->student);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    public function testAClientThatDeclaresNothingIsRefused(): void
    {
        $instance = $this->createInstance(supervised: true);

        $payload = $this->start($instance, []);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('supervision_unsupported', $payload->string('error'));
        // A displayable sentence, not a raw code: the app shows it as it stands.
        self::assertNotSame('', $payload->string('message'));
    }

    public function testAClientThatDeclaresTheCapabilityComposes(): void
    {
        $instance = $this->createInstance(supervised: true);

        $payload = $this->start($instance, ['supervision' => 'supported']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($payload->int('attemptId'));
        self::assertTrue($payload->bool('supervised'));
        // The key that owns the attempt, handed over so the app's beacons can authenticate.
        self::assertNotSame('', $payload->string('sessionKey'));
    }

    /** An ordinary quiz is untouched: nothing to declare, nothing refused, and no key invented. */
    public function testAnUnsupervisedQuizAsksForNothing(): void
    {
        $instance = $this->createInstance(supervised: false);

        $payload = $this->start($instance, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($payload->bool('supervised'));
        self::assertSame('', $payload->string('sessionKey'));
    }

    /** The card carries the padlock's reason, so the app can say why rather than merely refuse. */
    public function testTheListMarksTheSupervisedEvaluation(): void
    {
        $this->createInstance(supervised: true);

        $this->client->request('GET', '/api/quiz/mine', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
        $evaluations = $payload->objects('evaluations');

        self::assertCount(1, $evaluations);
        self::assertTrue($evaluations[0]->bool('supervised'));
    }

    /** The dispossessed client writes nothing into somebody's exam. */
    public function testABeaconWithTheWrongKeyIsRefused(): void
    {
        $instance = $this->createInstance(supervised: true);
        $started = $this->start($instance, ['supervision' => 'supported']);

        $this->client->request(
            'POST',
            \sprintf('/api/quiz/attempt/%d/event', $started->int('attemptId') ?? 0),
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['sessionKey' => 'not-the-key', 'type' => 'page_hidden', 'position' => 0], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    /** `TakenOver` is the server's own statement; a client claiming it would claim to have been dispossessed. */
    public function testAClientMayNotClaimToHaveBeenTakenOver(): void
    {
        $instance = $this->createInstance(supervised: true);
        $started = $this->start($instance, ['supervision' => 'supported']);

        $this->client->request(
            'POST',
            \sprintf('/api/quiz/attempt/%d/event', $started->int('attemptId') ?? 0),
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['sessionKey' => $started->string('sessionKey'), 'type' => 'taken_over'], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The start call's answer, read through the repo's own boundary object rather than as a raw
     * array: a JSON body is mixed by definition, and typing it once at the edge is the rule.
     *
     * @param array<string, string> $body
     */
    private function start(QuizInstance $instance, array $body): JsonRequestPayload
    {
        $this->client->request(
            'POST',
            '/api/quiz/'.$instance->getId().'/start',
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    private function createInstance(bool $supervised): QuizInstance
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'supervision.author'.($supervised ? '.on' : '.off'));

        $template = new QuizTemplate($author);
        $template->setName('Contrôle');
        $template->setCreatedBy($author);
        $entityManager->persist($template);

        $instance = new QuizInstance($this->program, $author);
        $instance->setName('Contrôle');
        $instance->setMode(QuizMode::Evaluation);
        $instance->setSourceTemplate($template);
        $instance->setSupervised($supervised);
        $instance->setQuestionCount(1);
        $instance->setDifficultyCounts(0, 1, 0);
        $entityManager->persist($instance);

        $question = new QuizInstanceQuestion($instance);
        $question->setType(QuestionType::Qcm);
        $question->setLabel('Question');
        $instance->addQuestion($question);
        $entityManager->persist($question);

        foreach ([['Bonne', true], ['Mauvaise', false]] as $index => [$label, $isCorrect]) {
            $answer = new QuizInstanceAnswer($question);
            $answer->setLabel($label);
            $answer->setIsCorrect($isCorrect);
            $answer->setOrderIndex($index);
            $question->addAnswer($answer);
            $entityManager->persist($answer);
        }

        $entityManager->flush();

        return $instance;
    }
}
