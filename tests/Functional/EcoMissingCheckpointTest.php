<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCheckpointReport;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Enum\EcoCourseMode;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A flag gone from the terrain, in a race run in order (D, 1, 2, 3, A, flag 2 missing). Before,
 * every flag after it was refused as out of order, to every runner: nobody could go on. Now a
 * runner reports it (« Balise introuvable ») and the next flag becomes the one expected for them;
 * the teacher reads the reports on the live screen and neutralises the flag for the whole race,
 * which nobody then has to find and which a complete run no longer needs.
 */
class EcoMissingCheckpointTest extends FunctionalTestCase
{
    private User $teacher;
    private EntityManagerInterface $entityManager;
    private EcoCourse $course;

    /** @var array<string, EcoCheckpoint> D, 1, 2, 3, A */
    private array $checkpoints = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.teacher');
        $this->course = $this->createRunningCourse();
    }

    public function testAReportedFlagIsPassedOverAndTheNextOneIsExpected(): void
    {
        $token = $this->join('lilou');
        self::assertSame('success', $this->scan($token, 'D')->string('result'));
        self::assertSame('success', $this->scan($token, '1')->string('result'));
        // Flag 2 is not there: 3 is refused, as it always was.
        self::assertSame('out_of_order', $this->scan($token, '3')->string('result'));

        $state = $this->report($token, '2');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([$this->id('2')], $state->ids('passedOverCheckpointIds'));
        self::assertSame(['3'], $this->names($state, 'isNext'));
        self::assertSame(['2'], $this->names($state, 'isReported'));

        self::assertSame('success', $this->scan($token, '3')->string('result'));
        self::assertSame('success', $this->scan($token, 'A')->string('result'));

        // Reported is not validated: the run stays incomplete until the teacher decides.
        $summary = $this->request('GET', '/api/eco/runner/summary?token='.$token);
        self::assertSame(2, $summary->int('checkpointsValidated'));
        self::assertSame(3, $summary->int('checkpointsTotal'));
    }

    public function testOnlyARegularFlagNotYetValidatedCanBeReported(): void
    {
        $token = $this->join('lilou');

        // Not on the course yet.
        $this->report($token, '2');
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $this->scan($token, 'D');
        $this->scan($token, '1');

        foreach (['D', 'A', '1'] as $label) {
            self::assertSame('reportRefused', $this->report($token, $label)->string('error'), $label);
            self::assertSame(409, $this->client->getResponse()->getStatusCode());
        }

        $this->request('POST', '/api/eco/runner/report', ['token' => $token, 'checkpointId' => 999999]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->request('POST', '/api/eco/runner/report', ['token' => 'nope', 'checkpointId' => $this->id('2')]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // Twice says nothing more.
        $this->report($token, '2');
        $this->report($token, '2');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->getRepository(EcoCheckpointReport::class)->count([]));
    }

    public function testTheTeacherNeutralisesAReportedFlagForEveryRunner(): void
    {
        $reporter = $this->join('lilou');
        $this->scan($reporter, 'D');
        $this->scan($reporter, '1');
        $this->report($reporter, '2');
        $other = $this->join('tomtom');
        $this->scan($other, 'D');
        $this->scan($other, '1');

        $this->client->loginUser($this->teacher);
        $live = $this->client->request('GET', \sprintf('/eco/courses/%d/live', $this->course->getId()));
        self::assertResponseIsSuccessful();
        $block = $live->filter('[data-eco-live-target="reports"]');
        self::assertStringContainsString('lilou', $block->text());
        $signature = (string) $live->filter('[data-controller="eco-live"]')->attr('data-eco-live-reports-signature-value');

        $this->client->request('POST', \sprintf('/eco/courses/%d/checkpoints/neutralize', $this->course->getId()), [
            '_token' => $this->csrfToken('eco_course_checkpoint_neutralize'),
            'checkpoint' => $this->id('2'),
            'action' => 'neutralize',
        ]);
        self::assertResponseRedirects(\sprintf('/eco/courses/%d/live', $this->course->getId()));

        // The live screen's poll sees the block change.
        $this->client->request('GET', \sprintf('/eco/courses/%d/live/data', $this->course->getId()));
        $data = JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
        self::assertNotSame($signature, $data->string('reportsSignature'));
        $fragment = $this->client->request('GET', \sprintf('/eco/courses/%d/live/reports', $this->course->getId()));
        self::assertStringContainsString('Rétablir', $fragment->text());

        // A runner who never reported it goes straight from 1 to 3, and their run is complete.
        self::assertSame('success', $this->scan($other, '3')->string('result'));
        $this->scan($other, 'A');
        $summary = $this->request('GET', '/api/eco/runner/summary?token='.$other);
        self::assertSame(2, $summary->int('checkpointsValidated'));
        self::assertSame(2, $summary->int('checkpointsTotal'));

        // Restored: flag 2 is expected again of whoever has not reported it.
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', \sprintf('/eco/courses/%d/live', $this->course->getId()));
        $this->client->request('POST', \sprintf('/eco/courses/%d/checkpoints/neutralize', $this->course->getId()), [
            '_token' => $this->csrfToken('eco_course_checkpoint_neutralize'),
            'checkpoint' => $this->id('2'),
            'action' => 'restore',
        ]);
        $third = $this->join('zoé');
        $this->scan($third, 'D');
        $this->scan($third, '1');
        self::assertSame('out_of_order', $this->scan($third, '3')->string('result'));
    }

    public function testDepartAndArriveeCannotBeNeutralised(): void
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', \sprintf('/eco/courses/%d/live', $this->course->getId()));
        $this->client->request('POST', \sprintf('/eco/courses/%d/checkpoints/neutralize', $this->course->getId()), [
            '_token' => $this->csrfToken('eco_course_checkpoint_neutralize'),
            'checkpoint' => $this->id('A'),
            'action' => 'neutralize',
        ]);

        $this->entityManager->clear();
        $course = $this->entityManager->find(EcoCourse::class, $this->course->getId());
        self::assertInstanceOf(EcoCourse::class, $course);
        self::assertCount(5, $course->getCountedCheckpoints());
    }

    /** @return list<string> the names of the checkpoints whose $flag is true */
    private function names(JsonRequestPayload $state, string $flag): array
    {
        return array_values(array_map(
            static fn (JsonRequestPayload $checkpoint): string => $checkpoint->string('name'),
            array_filter($state->objects('checkpoints'), static fn (JsonRequestPayload $checkpoint): bool => $checkpoint->bool($flag, false)),
        ));
    }

    private function id(string $label): int
    {
        return (int) $this->checkpoints[$label]->getId();
    }

    private function join(string $pseudo): string
    {
        return $this->request('POST', '/api/eco/runner/join', ['pseudo' => $pseudo, 'code' => $this->course->getCode()])->string('token');
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

    private function report(string $token, string $label): JsonRequestPayload
    {
        return $this->request('POST', '/api/eco/runner/report', [
            'token' => $token,
            'checkpointId' => $this->id($label),
            'latitude' => 45.83,
            'longitude' => 1.26,
        ]);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): JsonRequestPayload
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, \JSON_THROW_ON_ERROR) : null);

        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    /** D, 1, 2, 3, A, all located, run in imposed order. */
    private function createRunningCourse(): EcoCourse
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
        $course->setMode(EcoCourseMode::ImposedOrder);
        $course->start(new \DateTimeImmutable());
        $this->entityManager->persist($course);
        $this->entityManager->flush();

        return $course;
    }
}
