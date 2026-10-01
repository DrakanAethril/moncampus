<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcoAppEvent;
use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\EcoRunner;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One runner's race from the phone to the teacher's screen, while the course is still running:
 * the runner joins, scans Départ, a flag and Arrivée, and reads their recap; the teacher opens
 * that runner's race without closing the course, and does not see the runners still out.
 *
 * The runner API has no account (^/api/eco/runner is public): the join token is the identity.
 */
class EcoRunnerRaceTest extends FunctionalTestCase
{
    private User $teacher;
    private EntityManagerInterface $entityManager;
    private EcoCourse $course;

    /** @var array<string, EcoCheckpoint> keyed by type */
    private array $checkpoints = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.teacher');
        $this->course = $this->createRunningCourse();
    }

    public function testTheRunnerIsNeverHandedTheCheckpointCodes(): void
    {
        $join = $this->request('POST', '/api/eco/runner/join', ['pseudo' => 'lilou', 'code' => $this->course->getCode()]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $codes = array_map(static fn (JsonRequestPayload $checkpoint): string => $checkpoint->string('shortCode', 'absent'), $join->objects('checkpoints'));
        self::assertSame(['', '', ''], $codes);
    }

    public function testTheRecapIsRefusedUntilTheFinishIsScanned(): void
    {
        $token = $this->join('lilou');
        $this->scan($token, EcoCheckpointType::Start);

        $refused = $this->request('GET', '/api/eco/runner/summary?token='.$token);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('runnerNotFinished', $refused->string('error'));
    }

    public function testTheRecapCountsTheRegularFlagsAndTimesEachLeg(): void
    {
        $token = $this->join('lilou');
        $this->joinedAt($token, '2026-09-28T09:55:00+02:00');
        $this->scan($token, EcoCheckpointType::Start, '2026-09-28T10:00:00+02:00');
        // Typed by hand with a space in the middle, as read off the flag.
        $code = (string) $this->checkpoints[EcoCheckpointType::Checkpoint->value]->getShortCode();
        $this->scan($token, EcoCheckpointType::Checkpoint, '2026-09-28T10:04:00+02:00', substr($code, 0, 3).' '.strtolower(substr($code, 3)));
        $this->scan($token, EcoCheckpointType::Finish, '2026-09-28T10:10:30+02:00');

        $summary = $this->request('GET', '/api/eco/runner/summary?token='.$token);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('lilou', $summary->string('pseudo'));
        self::assertSame(630, $summary->int('durationSeconds'));
        // Départ and Arrivée are not flags to find: 1/1, like the race screen's header.
        self::assertSame(1, $summary->int('checkpointsValidated'));
        self::assertSame(1, $summary->int('checkpointsTotal'));
        self::assertSame(0, $summary->int('scanFailureCount'));
        $legs = $summary->objects('legs');
        self::assertCount(2, $legs);
        self::assertSame(240, $legs[0]->int('seconds'));
        self::assertSame(390, $legs[1]->int('seconds'));
    }

    public function testALegIsMeasuredOnTheFixesTheRunnerSentDuringIt(): void
    {
        $token = $this->join('lilou');
        $this->joinedAt($token, '2026-09-28T09:55:00+02:00');
        $this->scan($token, EcoCheckpointType::Start, '2026-09-28T10:00:00+02:00');
        // The phone sends UTC. Kept as UTC wall time, these read back two hours before the scans
        // and the leg found no fix at all: the flag-to-flag straight line, ~111 m.
        $this->request('POST', '/api/eco/runner/positions', ['token' => $token, 'points' => [
            // A detour ~155 m east and back.
            ['recordedAt' => '2026-09-28T08:01:00.000Z', 'latitude' => 45.8303, 'longitude' => 1.262, 'accuracy' => 6.0],
            ['recordedAt' => '2026-09-28T08:02:00.000Z', 'latitude' => 45.8307, 'longitude' => 1.262, 'accuracy' => 6.0],
            // Thrown 1.5 km away in 30 s: a jump, not a runner.
            ['recordedAt' => '2026-09-28T08:02:30.000Z', 'latitude' => 45.8305, 'longitude' => 1.28, 'accuracy' => 6.0],
            // Plausible pace, but the phone itself says it does not know within 80 m.
            ['recordedAt' => '2026-09-28T08:02:40.000Z', 'latitude' => 45.8308, 'longitude' => 1.2635, 'accuracy' => 80.0],
        ]]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->scan($token, EcoCheckpointType::Checkpoint, '2026-09-28T10:04:00+02:00');
        $this->scan($token, EcoCheckpointType::Finish, '2026-09-28T10:10:30+02:00');

        $legs = $this->request('GET', '/api/eco/runner/summary?token='.$token)->objects('legs');

        // Départ → fix → fix → flag: ~158 + 44 + 158 m.
        self::assertEqualsWithDelta(360, (int) $legs[0]->int('distanceMeters'), 20);
    }

    public function testAScanQueuedWithoutNetworkKeepsTheTimeItWasMade(): void
    {
        $token = $this->join('lilou');
        $this->joinedAt($token, '2026-09-28T09:55:00+02:00');

        // The phone's clock, in UTC, sent on reconnection long after.
        $this->scan($token, EcoCheckpointType::Start, '2026-09-28T08:00:00.000Z');

        self::assertSame('2026-09-28 10:00:00', $this->runner($token)->getStartedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAPhoneClockOutsideTheRaceIsNotBelieved(): void
    {
        $early = $this->join('lilou');
        $this->scan($early, EcoCheckpointType::Start, '2026-01-01T08:00:00Z');
        $late = $this->join('tomtom');
        $this->scan($late, EcoCheckpointType::Start, '2099-01-01T08:00:00Z');

        // Before the runner even joined, or still to come: the time of arrival instead.
        self::assertEqualsWithDelta(time(), $this->runner($early)->getStartedAt()?->getTimestamp(), 5);
        self::assertEqualsWithDelta(time(), $this->runner($late)->getStartedAt()?->getTimestamp(), 5);
    }

    public function testAnAppEventQueuedWithoutNetworkKeepsTheTimeItHappened(): void
    {
        $token = $this->join('lilou');
        $this->joinedAt($token, '2026-09-28T09:55:00+02:00');

        $this->request('POST', '/api/eco/runner/app-events', ['token' => $token, 'type' => 'left', 'at' => '2026-09-28T08:10:00.000Z']);
        self::assertSame('2026-09-28 10:10:00', $this->runner($token)->getAppLeftAt()?->format('Y-m-d H:i:s'));

        $this->request('POST', '/api/eco/runner/app-events', ['token' => $token, 'type' => 'returned', 'at' => '2026-09-28T08:12:30.000Z']);
        $event = $this->entityManager->getRepository(EcoAppEvent::class)->findOneBy(['runner' => $this->runner($token)]);
        self::assertInstanceOf(EcoAppEvent::class, $event);
        self::assertSame(150, $event->getDurationSeconds());
        self::assertNull($this->runner($token)->getAppLeftAt());
    }

    public function testAnAppEventDatedOutsideTheRaceIsStampedOnArrival(): void
    {
        $token = $this->join('lilou');

        $this->request('POST', '/api/eco/runner/app-events', ['token' => $token, 'type' => 'left', 'at' => '2099-01-01T08:00:00Z']);

        self::assertEqualsWithDelta(time(), $this->runner($token)->getAppLeftAt()?->getTimestamp(), 5);
    }

    public function testTheTeacherOpensAFinishedRunnerWhileTheRaceRunsButNotOneStillOut(): void
    {
        $finished = $this->join('lilou');
        $this->scan($finished, EcoCheckpointType::Start);
        $this->scan($finished, EcoCheckpointType::Checkpoint);
        $this->scan($finished, EcoCheckpointType::Finish);
        $racing = $this->join('tomtom');
        $this->scan($racing, EcoCheckpointType::Start);

        $this->client->loginUser($this->teacher);

        $live = $this->client->request('GET', \sprintf('/eco/courses/%d/live', $this->course->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $live->filter('[data-eco-live-target="pseudo"] a'));
        self::assertSame('lilou', trim($live->filter('[data-eco-live-target="pseudo"] a')->text()));

        $results = $this->client->request('GET', (string) $live->filter('[data-eco-live-target="pseudo"] a')->attr('href'));
        self::assertResponseIsSuccessful();
        $options = $results->filter('select[name="runner"] option')->each(static fn ($option): string => trim($option->text()));
        self::assertCount(1, $options);
        self::assertStringStartsWith('lilou', $options[0]);
    }

    private function join(string $pseudo): string
    {
        return $this->request('POST', '/api/eco/runner/join', ['pseudo' => $pseudo, 'code' => $this->course->getCode()])->string('token');
    }

    private function runner(string $token): EcoRunner
    {
        $runner = $this->entityManager->getRepository(EcoRunner::class)->findOneBy(['joinToken' => $token]);
        self::assertInstanceOf(EcoRunner::class, $runner);

        return $runner;
    }

    /** A race replayed on fixed dates needs a runner who joined before them. */
    private function joinedAt(string $token, string $at): void
    {
        $runner = $this->runner($token);
        new \ReflectionProperty(EcoRunner::class, 'joinedAt')->setValue($runner, new \DateTimeImmutable($at));
        $this->entityManager->flush();
    }

    private function scan(string $token, EcoCheckpointType $type, ?string $at = null, ?string $code = null): void
    {
        $checkpoint = $this->checkpoints[$type->value];
        $result = $this->request('POST', '/api/eco/runner/scan', [
            'token' => $token,
            'code' => $code ?? $checkpoint->getShortCode(),
            'latitude' => $checkpoint->getLatitude(),
            'longitude' => $checkpoint->getLongitude(),
            'scannedAt' => $at ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        self::assertSame('success', $result->string('result'), $type->value);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): JsonRequestPayload
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, \JSON_THROW_ON_ERROR) : null);

        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    /** A start, one flag and a finish, all located, and a course already started on them. */
    private function createRunningCourse(): EcoCourse
    {
        $parcours = new EcoParcours($this->teacher);
        $parcours->setName('Bois de la Bastide');
        $parcours->setCreatedBy($this->teacher);
        $this->entityManager->persist($parcours);

        foreach ([EcoCheckpointType::Start, EcoCheckpointType::Checkpoint, EcoCheckpointType::Finish] as $position => $type) {
            $checkpoint = new EcoCheckpoint($parcours);
            $checkpoint->setType($type);
            $checkpoint->setPosition($position);
            $checkpoint->setName($type->value);
            $checkpoint->setShortCode(strtoupper(substr(bin2hex(random_bytes(5)), 0, 7)));
            $checkpoint->locate(45.83 + $position / 1000, 1.26, new \DateTimeImmutable());
            $parcours->addCheckpoint($checkpoint);
            $this->entityManager->persist($checkpoint);
            $this->checkpoints[$type->value] = $checkpoint;
        }

        $course = new EcoCourse($parcours, $this->teacher);
        $course->setName('2NDE B — mercredi');
        $course->setCode(strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        $course->start(new \DateTimeImmutable());
        $this->entityManager->persist($course);
        $this->entityManager->flush();

        return $course;
    }
}
