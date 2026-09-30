<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Enum\EcoCourseMode;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Balises spécifiques »: a course run on flags 1 and 3 of a five-flag parcours. For that race
 * flag 2 does not exist - the runner is not shown it, its code is unknown, and nobody is expected
 * to find it - and « dans l'ordre » expects 1 before 3 where « au choix » does not.
 *
 * The runner app predates the mode: it is told imposed_order or free_order, which is all it needs
 * with the list of flags already cut down.
 */
class EcoSpecificCheckpointsRaceTest extends FunctionalTestCase
{
    private User $teacher;
    private EntityManagerInterface $entityManager;

    /** @var array<string, EcoCheckpoint> D, 1, 2, 3, A */
    private array $checkpoints = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.teacher');
    }

    public function testTheRunnerIsShownOnlyTheChosenFlagsAndInOrderIsImposedOrder(): void
    {
        $course = $this->createRunningCourse(ordered: true);

        $join = $this->request('POST', '/api/eco/runner/join', ['pseudo' => 'lilou', 'code' => $course->getCode()]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('imposed_order', $join->string('mode'));
        self::assertSame('balises spécifiques · dans l’ordre', $join->string('modeLabel'));
        $names = array_map(static fn (JsonRequestPayload $checkpoint): string => $checkpoint->string('name'), $join->objects('checkpoints'));
        self::assertSame(['D', '1', '3', 'A'], $names);
    }

    public function testAFlagLeftOutIsUnknownAndInOrderMeansTheChosenOrder(): void
    {
        $course = $this->createRunningCourse(ordered: true);
        $token = $this->join($course);

        self::assertSame('success', $this->scan($token, 'D')->string('result'));

        $leftOut = $this->scan($token, '2');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('checkpointNotFound', $leftOut->string('error'));

        self::assertSame('out_of_order', $this->scan($token, '3')->string('result'));
        // Flag 2 is not expected in between: after 1 comes 3.
        self::assertSame('success', $this->scan($token, '1')->string('result'));
        self::assertSame('success', $this->scan($token, '3')->string('result'));
        self::assertSame('success', $this->scan($token, 'A')->string('result'));

        $summary = $this->request('GET', '/api/eco/runner/summary?token='.$token);
        self::assertSame(2, $summary->int('checkpointsValidated'));
        self::assertSame(2, $summary->int('checkpointsTotal'));
        self::assertSame('imposed_order', $summary->string('mode'));
    }

    public function testInAnyOrderTheChosenFlagsAreTakenAsTheRunnerLikes(): void
    {
        $course = $this->createRunningCourse(ordered: false);
        $token = $this->join($course);

        self::assertSame('free_order', $this->request('GET', '/api/eco/runner/state?token='.$token)->string('mode'));
        self::assertSame('success', $this->scan($token, 'D')->string('result'));
        self::assertSame('success', $this->scan($token, '3')->string('result'));
        self::assertSame('success', $this->scan($token, '1')->string('result'));
        self::assertSame('success', $this->scan($token, 'A')->string('result'));
    }

    private function join(EcoCourse $course): string
    {
        return $this->request('POST', '/api/eco/runner/join', ['pseudo' => 'lilou', 'code' => $course->getCode()])->string('token');
    }

    private function scan(string $token, string $label): JsonRequestPayload
    {
        $checkpoint = $this->checkpoints[$label];

        return $this->request('POST', '/api/eco/runner/scan', [
            'token' => $token,
            'code' => $checkpoint->getShortCode(),
            'latitude' => $checkpoint->getLatitude(),
            'longitude' => $checkpoint->getLongitude(),
        ]);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): JsonRequestPayload
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, \JSON_THROW_ON_ERROR) : null);

        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    /** D, 1, 2, 3, A, all located; a started course on 1 and 3. */
    private function createRunningCourse(bool $ordered): EcoCourse
    {
        $parcours = new EcoParcours($this->teacher);
        $parcours->setName('Bois de la Bastide');
        $parcours->setCreatedBy($this->teacher);
        $this->entityManager->persist($parcours);

        $layout = ['D' => EcoCheckpointType::Start, '1' => EcoCheckpointType::Checkpoint, '2' => EcoCheckpointType::Checkpoint, '3' => EcoCheckpointType::Checkpoint, 'A' => EcoCheckpointType::Finish];
        $position = 0;
        foreach ($layout as $label => $type) {
            $checkpoint = new EcoCheckpoint($parcours);
            $checkpoint->setType($type);
            $checkpoint->setPosition($position);
            $checkpoint->setName((string) $label);
            $checkpoint->setShortCode(strtoupper(substr(bin2hex(random_bytes(5)), 0, 7)));
            $checkpoint->locate(45.83 + $position / 1000, 1.26, new \DateTimeImmutable());
            $parcours->addCheckpoint($checkpoint);
            $this->entityManager->persist($checkpoint);
            $this->checkpoints[(string) $label] = $checkpoint;
            ++$position;
        }

        $course = new EcoCourse($parcours, $this->teacher);
        $course->setName('2NDE B — mercredi');
        $course->setCode(strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        $course->setMode(EcoCourseMode::SpecificCheckpoints);
        $course->setSpecificOrdered($ordered);
        $course->addSpecificCheckpoint($this->checkpoints['1']);
        $course->addSpecificCheckpoint($this->checkpoints['3']);
        $course->start(new \DateTimeImmutable());
        $this->entityManager->persist($course);
        $this->entityManager->flush();

        return $course;
    }
}
