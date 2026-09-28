<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * The teacher app's parcours screen: the radius of each flag, saved as screen 1e saves it, and the
 * IGN's reading of the ground - read, asked for, and said to be out of date once a flag moved.
 */
class EcoTeacherParcoursApiTest extends FunctionalTestCase
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

    public function testTheRadiusOfEachFlagIsSavedAndReadBackInPositionOrder(): void
    {
        $parcours = $this->createParcours(located: true);
        [$start, $flag, $finish] = $this->checkpointsOf($parcours);
        $other = $this->checkpointsOf($this->createParcours(located: true))[1];

        $payload = $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/tolerances', $parcours->getId()), ['tolerances' => [
            (string) $flag->getId() => 35,
            // Under a metre nobody could validate the flag: raised, not refused.
            (string) $finish->getId() => 0,
            // Not a number: the flag keeps its radius.
            (string) $start->getId() => 'large',
            // Another parcours' flag is never touched from this one.
            (string) $other->getId() => 99,
        ]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $tolerances = array_map(static fn (JsonRequestPayload $checkpoint): ?int => $checkpoint->int('toleranceMeters'), $payload->objects('checkpoints'));
        self::assertSame([EcoCheckpoint::DEFAULT_TOLERANCE_METERS, 35, 1], $tolerances);
        self::assertSame(EcoCheckpoint::DEFAULT_TOLERANCE_METERS, $payload->int('defaultToleranceMeters'));
        $this->entityManager->refresh($other);
        self::assertSame(EcoCheckpoint::DEFAULT_TOLERANCE_METERS, $other->getToleranceMeters());
    }

    public function testAnotherTeachersParcoursCannotBeEdited(): void
    {
        $parcours = $this->createParcours(located: true);
        $this->authenticateAs($this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.other'));

        $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/tolerances', $parcours->getId()), ['tolerances' => []]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->request('GET', \sprintf('/api/eco/teacher/parcours/%d/terrain', $parcours->getId()));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheGroundCannotBeReadBeforeAnyFlagStandsOnIt(): void
    {
        $parcours = $this->createParcours(located: false);

        $sheet = $this->request('GET', \sprintf('/api/eco/teacher/parcours/%d/terrain', $parcours->getId()));
        self::assertFalse($sheet->bool('canAnalyze', true));
        self::assertNull($sheet->toArray()['analysis'] ?? null);

        $refused = $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/terrain', $parcours->getId()));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('noLocatedCheckpoint', $refused->string('error'));
    }

    public function testAnAnalysisAskedForIsPendingUntilTheScheduledReadWritesIt(): void
    {
        $parcours = $this->createParcours(located: true);

        $sheet = $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/terrain', $parcours->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($sheet->bool('pending'));
        self::assertFalse($sheet->bool('canAnalyze', true));
    }

    public function testTheReadingCarriesTheLegsAndTheSafetySheetAndGoesStaleWhenAFlagMoves(): void
    {
        $parcours = $this->createParcours(located: true);
        [$start, $flag] = $this->checkpointsOf($parcours);
        $start->recordTerrain(312.4, 18.0, new \DateTimeImmutable());
        $parcours->recordTerrainAnalysis([
            'fingerprint' => $parcours->locationFingerprint(),
            'commune' => 'Limoges',
            'nearbyPlace' => 'Le Mas Éloi',
            'publicForests' => ['Forêt domaniale des Vaseix'],
            'legs' => [[
                'fromId' => $start->getId(), 'toId' => $flag->getId(), 'fromLabel' => 'D', 'toLabel' => '1',
                'straightMeters' => 111.2, 'climbMeters' => 6.0, 'descentMeters' => 2.0, 'maxSlopePercent' => 18.0,
                'forestShare' => 0.4, 'pathMeters' => 180.0, 'pathRatio' => 1.6, 'effortKm' => 0.17,
            ]],
            'checkpoints' => [['id' => $start->getId(), 'label' => 'D', 'nearestCarRoadMeters' => 620.0, 'nearestWaterMeters' => null, 'publicForest' => 'Forêt domaniale des Vaseix']],
            'routes' => ['1-2' => 180.0],
            'incomplete' => false,
        ], new \DateTimeImmutable());
        $this->entityManager->flush();

        $sheet = $this->request('GET', \sprintf('/api/eco/teacher/parcours/%d/terrain', $parcours->getId()));

        self::assertTrue($sheet->bool('current'));
        $analysis = $sheet->object('analysis');
        self::assertSame('Limoges', $analysis->string('commune'));
        self::assertSame(['Forêt domaniale des Vaseix'], $analysis->strings('publicForests'));
        self::assertFalse($analysis->has('routes'));
        self::assertSame(1.6, $analysis->objects('legs')[0]->float('pathRatio'));
        $safety = $analysis->objects('checkpoints');
        self::assertSame(['D', '1', 'A'], array_map(static fn (JsonRequestPayload $row): string => $row->string('label'), $safety));
        self::assertSame(312.4, $safety[0]->float('groundAltitude'));
        self::assertSame(620.0, $safety[0]->float('nearestCarRoadMeters'));
        // A flag the analysis never saw has no road and no water, rather than someone else's.
        self::assertNull($safety[1]->float('nearestCarRoadMeters'));

        $flag->locate(45.9, 1.3, new \DateTimeImmutable());
        $this->entityManager->flush();

        $stale = $this->request('GET', \sprintf('/api/eco/teacher/parcours/%d/terrain', $parcours->getId()));
        self::assertFalse($stale->bool('current', true));
        self::assertSame('Limoges', $stale->object('analysis')->string('commune'));
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

    /** @return list<EcoCheckpoint> in position order */
    private function checkpointsOf(EcoParcours $parcours): array
    {
        $checkpoints = $parcours->getCheckpoints()->toArray();
        usort($checkpoints, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        return $checkpoints;
    }

    /** A start, one flag and a finish - all located, or none. */
    private function createParcours(bool $located): EcoParcours
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
            if ($located) {
                $checkpoint->locate(45.83 + $position / 1000, 1.26, new \DateTimeImmutable());
            }
            $parcours->addCheckpoint($checkpoint);
            $this->entityManager->persist($checkpoint);
        }

        $this->entityManager->flush();

        return $parcours;
    }
}
