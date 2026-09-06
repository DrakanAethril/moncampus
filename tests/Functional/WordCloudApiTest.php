<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Enum\WordCloudWordLength;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * The three routes of /api/word-clouds - what the Flutter app is allowed to do.
 *
 * The point of testing them apart from the web screens is that they are a *second door onto the
 * same rules*: the period, the quota and the duplicate all live in
 * App\Service\WordCloud\WordCloudSubmissionService, and the whole design rests on that being true
 * of the app as much as of the browser. A word the web form refuses and the API takes would make
 * every one of them decorative.
 *
 * Authentication is a real LexikJWT token: /api is a stateless firewall, and a session login would
 * be proving something about a door the app never uses.
 */
class WordCloudApiTest extends FunctionalTestCase
{
    private User $student;
    private User $teacher;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.cloud.student');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.cloud.teacher');
        $this->outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'api.cloud.outsider');
    }

    public function testTheBannerAnnouncesTheOpenCloudOfTheStudentsClass(): void
    {
        $cloud = $this->openCloud();

        $announced = $this->get($this->student, '/api/word-clouds/active')->object('cloud');

        self::assertSame($cloud->getId(), $announced->int('id'));
        self::assertSame('Quels mots associez-vous à la cybersécurité ?', $announced->string('question'));
    }

    public function testAStudentOfAnotherClassIsToldOfNothing(): void
    {
        $this->openCloud();

        self::assertTrue($this->get($this->outsider, '/api/word-clouds/active')->object('cloud')->isEmpty());
    }

    /**
     * A teacher has no cloud to answer, and must be told so with an empty body rather than a 403:
     * a denied access is logged at *error* level, and the app is installed by the whole staff.
     */
    public function testATeacherGetsAnEmptyBannerRatherThanARefusal(): void
    {
        $this->openCloud();

        $this->authorize($this->teacher);
        $this->client->request('GET', '/api/word-clouds/active');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->body()->object('cloud')->isEmpty());
    }

    public function testAClosedCloudIsNotAnnounced(): void
    {
        $cloud = $this->openCloud();
        $cloud->setClosedAt(new \DateTimeImmutable());
        $this->entityManager()->flush();

        self::assertTrue($this->get($this->student, '/api/word-clouds/active')->object('cloud')->isEmpty());
    }

    public function testTheDetailCarriesWhatTheScreenNeedsToDrawItself(): void
    {
        $cloud = $this->openCloud();

        $body = $this->get($this->student, '/api/word-clouds/'.$cloud->getId());

        self::assertSame('open', $body->string('status'));
        self::assertTrue($body->bool('canSubmit'));
        self::assertSame(3, $body->int('remainingWords'));
        self::assertSame(30, $body->int('maxCharacters'));
        self::assertSame([], $body->objects('ownWords'));
        // Off by default: a cloud on every desk is a cloud nobody looks up from.
        self::assertTrue($body->object('cloud')->isEmpty());
    }

    /** « Illimités » travels as null, never as a large number the app would have to recognise. */
    public function testAnUnlimitedCloudReportsNoRemainingCount(): void
    {
        $cloud = $this->openCloud();
        $cloud->setWordsPerStudent(null);
        $this->entityManager()->flush();

        self::assertNull($this->get($this->student, '/api/word-clouds/'.$cloud->getId())->int('remainingWords'));
    }

    public function testAWordIsTakenAndComesBackInTheState(): void
    {
        $cloud = $this->openCloud();

        $body = $this->post($this->student, '/api/word-clouds/'.$cloud->getId().'/submit', ['words' => ['pare-feu']]);

        self::assertSame(['pare-feu'], $body->strings('accepted'));
        self::assertSame([], $body->objects('refusals'));
        self::assertSame(2, $body->object('state')->int('remainingWords'));

        $own = $body->object('state')->objects('ownWords');
        self::assertCount(1, $own);
        self::assertSame('pare-feu', $own[0]->string('text'));
        self::assertFalse($own[0]->bool('rejected'));
        self::assertFalse($own[0]->bool('pending'));
    }

    /** One box may be refused without the others being thrown away. */
    public function testARefusalInTheMiddleDoesNotLoseTheWordsAroundIt(): void
    {
        $cloud = $this->openCloud();

        $body = $this->post($this->student, '/api/word-clouds/'.$cloud->getId().'/submit', [
            'words' => ['pare-feu', 'Pare-Feu', 'phishing'],
        ]);

        self::assertSame(['pare-feu', 'phishing'], $body->strings('accepted'));
        self::assertSame('already_proposed', $body->objects('refusals')[0]->string('reason'));
        self::assertNotSame('', $body->objects('refusals')[0]->string('message'));
    }

    /** The single-word spelling the app may prefer, on the same endpoint. */
    public function testASingleWordMayBeSentOnItsOwn(): void
    {
        $cloud = $this->openCloud();

        self::assertSame(['vpn'], $this->post($this->student, '/api/word-clouds/'.$cloud->getId().'/submit', ['word' => 'vpn'])->strings('accepted'));
    }

    /**
     * The line the whole API exists to keep: the app is refused exactly what the browser is
     * refused, and by the same code.
     */
    public function testTheServerRefusesAWordOnAClosedCloudThroughTheAppToo(): void
    {
        $cloud = $this->openCloud();
        $cloud->setClosedAt(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $body = $this->post($this->student, '/api/word-clouds/'.$cloud->getId().'/submit', ['words' => ['phishing']]);

        self::assertSame([], $body->strings('accepted'));
        self::assertSame('closed', $body->objects('refusals')[0]->string('reason'));
        self::assertFalse($body->object('state')->bool('canSubmit'));
    }

    public function testTheQuotaIsEnforcedThroughTheAppToo(): void
    {
        $cloud = $this->openCloud();

        $body = $this->post($this->student, '/api/word-clouds/'.$cloud->getId().'/submit', [
            'words' => ['pare-feu', 'phishing', 'vpn', 'rgpd'],
        ]);

        self::assertSame(['pare-feu', 'phishing', 'vpn'], $body->strings('accepted'));
        self::assertSame('quota_reached', $body->objects('refusals')[0]->string('reason'));
        self::assertSame(0, $body->object('state')->int('remainingWords'));
        self::assertFalse($body->object('state')->bool('canSubmit'));
    }

    /** A question never put to somebody does not exist for them - a 404, not a « interdit ». */
    public function testACloudOfAnotherClassIsNotFound(): void
    {
        $cloud = $this->openCloud();

        $this->authorize($this->outsider);
        $this->client->request('GET', '/api/word-clouds/'.$cloud->getId());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/word-clouds/'.$cloud->getId().'/submit', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['words' => ['x']], \JSON_THROW_ON_ERROR));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** Switched on, the cloud rides along with the answer - already weighted, rung and all. */
    public function testTheCloudTravelsWeightedWhenTheTeacherAskedForIt(): void
    {
        $cloud = $this->openCloud();
        $cloud->setVisibleToStudents(true);
        $this->entityManager()->flush();

        $body = $this->post($this->student, '/api/word-clouds/'.$cloud->getId().'/submit', ['words' => ['pare-feu']]);

        $words = $body->object('state')->objects('cloud');
        self::assertCount(1, $words);
        self::assertSame('pare-feu', $words[0]->string('word'));
        self::assertSame(1, $words[0]->int('count'));
        self::assertSame(44, $words[0]->int('size'));
        self::assertSame('top', $words[0]->string('step'));
    }

    private function openCloud(): WordCloud
    {
        $program = $this->createProgram([$this->student], [$this->teacher]);
        $now = new \DateTimeImmutable();

        $cloud = new WordCloud();
        $cloud->setProgram($program)
            ->setTeacher($this->teacher)
            ->setCreatedBy($this->teacher)
            ->setName('Cybersécurité')
            ->setQuestion('Quels mots associez-vous à la cybersécurité ?')
            ->setWordLength(WordCloudWordLength::OneWord)
            ->setWordsPerStudent(3)
            ->setModeration(false)
            ->setOpensAt($now->modify('-5 minutes'))
            ->setClosesAt($now->modify('+30 minutes'));

        $this->entityManager()->persist($cloud);
        $this->entityManager()->flush();

        return $cloud;
    }

    private function get(User $user, string $path): JsonRequestPayload
    {
        $this->authorize($user);
        $this->client->request('GET', $path);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return $this->body();
    }

    /** @param array<string, mixed> $payload */
    private function post(User $user, string $path, array $payload): JsonRequestPayload
    {
        $this->authorize($user);
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return $this->body();
    }

    /**
     * The answer, read through the app's own typed reader rather than a bare json_decode - which is
     * also the closest a test gets to reading it the way the Flutter client will.
     */
    private function body(): JsonRequestPayload
    {
        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    /** The Bearer header every /api/* route expects - the same one the mobile app sends. */
    private function authorize(User $user): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
