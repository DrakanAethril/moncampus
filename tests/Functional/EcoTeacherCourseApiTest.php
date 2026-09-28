<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * The e-CO teacher app runs its courses from the phone: the parcours whose every flag is located,
 * a course created on one, started, then stopped. The same rules as screen 1g - the web's own
 * EcoCourseType, EcoCourse::start()/close() - reached through the stateless `/api` firewall, hence
 * a real LexikJWT token rather than a session login.
 */
class EcoTeacherCourseApiTest extends FunctionalTestCase
{
    private User $teacher;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.teacher');
        $this->authenticateAs($this->teacher);
    }

    public function testOnlyTheParcoursWhoseFlagsAreAllLocatedAreListedAsReady(): void
    {
        $ready = $this->createParcours('Bois de la Bastide', located: true);
        $this->createParcours('Parc en cours de pose', located: false);

        $payload = $this->request('GET', '/api/eco/teacher/parcours/ready');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $names = array_map(static fn (JsonRequestPayload $row): string => $row->string('name'), $payload->objects('parcours'));
        self::assertSame(['Bois de la Bastide'], $names);
        self::assertSame($ready->getId(), $payload->objects('parcours')[0]->int('id'));
        self::assertSame(3, $payload->objects('parcours')[0]->int('checkpointCount'));
    }

    public function testACourseIsCreatedWithAJoinCodeAndWithoutAnAllowanceInImposedOrder(): void
    {
        $parcours = $this->createParcours('Bois de la Bastide', located: true);

        $payload = $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/courses', $parcours->getId()), [
            'name' => '2NDE B — mercredi',
            'mode' => 'imposed_order',
            'timeLimitMinutes' => 45,
            'mapVisibility' => 'next_only',
            'teamsEnabled' => true,
            'safetyAlertsEnabled' => false,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $course = $payload->object('course');
        self::assertSame('2NDE B — mercredi', $course->string('name'));
        self::assertSame('prepared', $course->string('status'));
        self::assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $course->string('code'));
        // Imposed order is ranked on time: an allowance sent with it is not kept.
        self::assertNull($course->int('timeLimitMinutes'));
        self::assertSame('next_only', $course->string('mapVisibility'));
        self::assertTrue($course->bool('teamsEnabled'));
        self::assertFalse($course->bool('safetyAlertsEnabled', true));
    }

    public function testTheAllowanceIsKeptForAModePlayedAgainstTheClock(): void
    {
        $parcours = $this->createParcours('Bois de la Bastide', located: true);

        $payload = $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/courses', $parcours->getId()), [
            'name' => 'Course au score',
            'mode' => 'score',
            'timeLimitMinutes' => 30,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame(30, $payload->object('course')->int('timeLimitMinutes'));
    }

    public function testACourseWithoutANameIsRefusedAndSaysWhichField(): void
    {
        $parcours = $this->createParcours('Bois de la Bastide', located: true);

        $payload = $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/courses', $parcours->getId()), ['name' => '   ']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalidCourse', $payload->string('error'));
        self::assertNotSame('', $payload->object('fields')->string('name'));
    }

    public function testNoCourseRunsOnAParcoursStillBeingLaidOut(): void
    {
        $parcours = $this->createParcours('Parc en cours de pose', located: false);

        $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/courses', $parcours->getId()), ['name' => 'Trop tôt']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testACourseIsStartedThenStoppedAndNeitherStepRunsTwice(): void
    {
        $course = $this->createCourse($this->createParcours('Bois de la Bastide', located: true));
        $id = $course->getId();

        $started = $this->request('POST', \sprintf('/api/eco/teacher/courses/%d/start', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('in_progress', $started->object('course')->string('status'));
        self::assertNotSame('', $started->object('course')->string('startedAt'));

        $inProgress = $this->request('GET', '/api/eco/teacher/courses/in-progress');
        self::assertSame([$id], array_map(static fn (JsonRequestPayload $row): ?int => $row->int('id'), $inProgress->objects('courses')));

        $this->request('POST', \sprintf('/api/eco/teacher/courses/%d/start', $id));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $closed = $this->request('POST', \sprintf('/api/eco/teacher/courses/%d/close', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('closed', $closed->object('course')->string('status'));

        $again = $this->request('POST', \sprintf('/api/eco/teacher/courses/%d/close', $id));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('courseNotInProgress', $again->string('error'));
    }

    public function testAnotherTeachersCourseCannotBeStarted(): void
    {
        $course = $this->createCourse($this->createParcours('Bois de la Bastide', located: true));
        $this->authenticateAs($this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.other'));

        $this->request('POST', \sprintf('/api/eco/teacher/courses/%d/start', $course->getId()));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): JsonRequestPayload
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, \JSON_THROW_ON_ERROR) : null);

        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    private function authenticateAs(User $user): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    /** A start, one flag and a finish - all located, or the flag left to place. */
    private function createParcours(string $name, bool $located): EcoParcours
    {
        $parcours = new EcoParcours($this->teacher);
        $parcours->setName($name);
        $parcours->setCreatedBy($this->teacher);
        $this->entityManager->persist($parcours);

        foreach ([EcoCheckpointType::Start, EcoCheckpointType::Checkpoint, EcoCheckpointType::Finish] as $position => $type) {
            $checkpoint = new EcoCheckpoint($parcours);
            $checkpoint->setType($type);
            $checkpoint->setPosition($position);
            $checkpoint->setName($type->value);
            $checkpoint->setShortCode(strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)));
            if ($located || EcoCheckpointType::Checkpoint !== $type) {
                $checkpoint->locate(45.83 + $position / 1000, 1.26, new \DateTimeImmutable());
            }
            $parcours->addCheckpoint($checkpoint);
            $this->entityManager->persist($checkpoint);
        }

        $this->entityManager->flush();

        return $parcours;
    }

    private function createCourse(EcoParcours $parcours): EcoCourse
    {
        $course = new EcoCourse($parcours, $this->teacher);
        $course->setName('2NDE B — mercredi');
        $course->setCode(strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        $this->entityManager->persist($course);
        $this->entityManager->flush();

        return $course;
    }
}
