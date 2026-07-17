<?php

namespace App\Controller\Api;

use App\Entity\EcoAppEvent;
use App\Entity\EcoCheckpoint;
use App\Entity\EcoCheckpointScan;
use App\Entity\EcoCourse;
use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use App\Enum\EcoCourseStatus;
use App\Enum\EcoScanMethod;
use App\Enum\EcoScanResult;
use App\Repository\EcoAppEventRepository;
use App\Repository\EcoCourseRepository;
use App\Repository\EcoRunnerRepository;
use App\Service\EcoScanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mobile API for e-CO runners - no account at all (config/packages/security.yaml carves
 * ^/api/eco/runner out to PUBLIC_ACCESS): identity across calls is the $joinToken issued by
 * join(), which the app persists locally and resends as {token} in every request body. That same
 * persisted token is also what "reprise après crash" resumes from - state() rebuilds the app's UI
 * (chrono, progress) from server state instead of the app needing to remember anything itself.
 *
 * Offline-first is a mobile-app-side concern (local queue + resync), not this API's - every
 * endpoint here is a plain synchronous write, replayed by the app whenever it does have network.
 */
class EcoRunnerApiController extends AbstractController
{
    #[Route(path: '/api/eco/runner/join', name: 'api_eco_runner_join', methods: ['POST'])]
    public function join(Request $request, EcoCourseRepository $courseRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $payload = $this->decode($request);
        $pseudo = trim((string) ($payload['pseudo'] ?? ''));
        $code = mb_strtoupper(trim((string) ($payload['code'] ?? '')));

        if ('' === $pseudo || '' === $code) {
            return $this->json(['error' => 'pseudoAndCodeRequired'], 422);
        }

        $course = $courseRepository->findOneByCode($code);
        if (null === $course) {
            return $this->json(['error' => 'courseNotFound'], 404);
        }
        if (EcoCourseStatus::InProgress !== $course->getStatus()) {
            return $this->json(['error' => 'courseNotInProgress'], 409);
        }

        $runner = new EcoRunner($course, $pseudo, bin2hex(random_bytes(32)));
        $entityManager->persist($runner);
        $entityManager->flush();

        return $this->json($this->formatJoin($runner, $course));
    }

    #[Route(path: '/api/eco/runner/scan', name: 'api_eco_runner_scan', methods: ['POST'])]
    public function scan(Request $request, EcoRunnerRepository $runnerRepository, EcoScanService $scanService): JsonResponse
    {
        $payload = $this->decode($request);
        $runner = $this->resolveRunner($payload, $runnerRepository);
        if (null === $runner) {
            return $this->json(['error' => 'invalidToken'], 401);
        }

        $shortCode = mb_strtoupper(trim((string) ($payload['code'] ?? '')));
        $checkpoint = null;
        foreach ($runner->getCourse()->getParcours()->getCheckpoints() as $candidate) {
            if ($candidate->getShortCode() === $shortCode) {
                $checkpoint = $candidate;

                break;
            }
        }
        if (null === $checkpoint) {
            return $this->json(['error' => 'checkpointNotFound'], 404);
        }

        $method = 'manual_code' === ($payload['method'] ?? null) ? EcoScanMethod::ManualCode : EcoScanMethod::QrScan;
        $latitude = isset($payload['latitude']) ? (float) $payload['latitude'] : null;
        $longitude = isset($payload['longitude']) ? (float) $payload['longitude'] : null;
        $scannedAt = isset($payload['scannedAt']) ? new \DateTimeImmutable((string) $payload['scannedAt']) : new \DateTimeImmutable();

        $scan = $scanService->scan($runner, $checkpoint, $latitude, $longitude, $scannedAt, $method);

        return $this->json($this->formatScan($scan));
    }

    #[Route(path: '/api/eco/runner/positions', name: 'api_eco_runner_positions', methods: ['POST'])]
    public function positions(Request $request, EcoRunnerRepository $runnerRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $payload = $this->decode($request);
        $runner = $this->resolveRunner($payload, $runnerRepository);
        if (null === $runner) {
            return $this->json(['error' => 'invalidToken'], 401);
        }

        $points = \is_array($payload['points'] ?? null) ? $payload['points'] : [];
        $latestAt = null;
        $latestLat = null;
        $latestLng = null;

        foreach ($points as $point) {
            if (!isset($point['latitude'], $point['longitude'], $point['recordedAt'])) {
                continue;
            }
            $recordedAt = new \DateTimeImmutable((string) $point['recordedAt']);
            $latitude = (float) $point['latitude'];
            $longitude = (float) $point['longitude'];

            $entityManager->persist(new EcoPositionPing($runner, $recordedAt, $latitude, $longitude));

            if (null === $latestAt || $recordedAt > $latestAt) {
                $latestAt = $recordedAt;
                $latestLat = $latitude;
                $latestLng = $longitude;
            }
        }

        if (null !== $latestAt && null !== $latestLat && null !== $latestLng) {
            $runner->updateLastPosition($latestLat, $latestLng, $latestAt);
        }

        $entityManager->flush();

        return $this->json(['success' => true, 'accepted' => \count($points)]);
    }

    #[Route(path: '/api/eco/runner/sos', name: 'api_eco_runner_sos', methods: ['POST'])]
    public function sos(Request $request, EcoRunnerRepository $runnerRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $payload = $this->decode($request);
        $runner = $this->resolveRunner($payload, $runnerRepository);
        if (null === $runner) {
            return $this->json(['error' => 'invalidToken'], 401);
        }

        $runner->triggerSos(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/api/eco/runner/app-events', name: 'api_eco_runner_app_events', methods: ['POST'])]
    public function appEvent(Request $request, EcoRunnerRepository $runnerRepository, EcoAppEventRepository $appEventRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $payload = $this->decode($request);
        $runner = $this->resolveRunner($payload, $runnerRepository);
        if (null === $runner) {
            return $this->json(['error' => 'invalidToken'], 401);
        }

        $type = (string) ($payload['type'] ?? '');
        $at = isset($payload['at']) ? new \DateTimeImmutable((string) $payload['at']) : new \DateTimeImmutable();

        if ('left' === $type) {
            $entityManager->persist(new EcoAppEvent($runner, $at));
            $runner->setAppLeftAt($at);
        } elseif ('returned' === $type) {
            $openEvent = $appEventRepository->findOneBy(['runner' => $runner, 'returnedAt' => null], ['leftAt' => 'DESC']);
            $openEvent?->markReturned($at);
            $runner->setAppLeftAt(null);
        } else {
            return $this->json(['error' => 'invalidType'], 422);
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // "Reprise après crash" (design's own term) - the app calls this on launch with its locally
    // persisted token to rebuild chrono/progress/next-checkpoint state instead of assuming
    // anything client-side survived the crash.
    #[Route(path: '/api/eco/runner/state', name: 'api_eco_runner_state', methods: ['GET'])]
    public function state(Request $request, EcoRunnerRepository $runnerRepository): JsonResponse
    {
        $runner = $this->resolveRunner(['token' => $request->query->get('token')], $runnerRepository);
        if (null === $runner) {
            return $this->json(['error' => 'invalidToken'], 401);
        }

        return $this->json($this->formatJoin($runner, $runner->getCourse()));
    }

    /** @param array<string, mixed> $payload */
    private function resolveRunner(array $payload, EcoRunnerRepository $runnerRepository): ?EcoRunner
    {
        $token = (string) ($payload['token'] ?? '');
        if ('' === $token) {
            return null;
        }

        return $runnerRepository->findOneByJoinToken($token);
    }

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /** @return array{runnerId: int, token: string, pseudo: string, status: string, courseName: string, mode: string, mapVisibility: string, startedAt: ?string, checkpoints: list<array{id: int, shortCode: string, name: string, position: int, type: string}>} */
    private function formatJoin(EcoRunner $runner, EcoCourse $course): array
    {
        // Checkpoints already successfully scanned - essential for both normal progress display
        // and "reprise après crash" (GET /state reuses this same method): the app must never
        // assume its own local state survived, only what the server actually recorded.
        $validatedIds = array_unique(array_map(
            static fn (EcoCheckpointScan $scan): int => $scan->getCheckpoint()->getId(),
            array_filter(
                $runner->getScans()->toArray(),
                static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult(),
            ),
        ));

        return [
            'runnerId' => $runner->getId(),
            'token' => $runner->getJoinToken(),
            'pseudo' => $runner->getPseudo(),
            'status' => $runner->getStatus()->value,
            'courseName' => $course->getName(),
            'mode' => $course->getMode()->value,
            'mapVisibility' => $course->getMapVisibility()->value,
            'startedAt' => $runner->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'finishedAt' => $runner->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'validatedCheckpointIds' => array_values($validatedIds),
            'checkpoints' => $this->formatCheckpointsForMap($course, $validatedIds),
        ];
    }

    // Coordinates are filtered server-side per EcoCourse::$mapVisibility - never sent to the
    // client and then merely hidden in the UI, since a runner could otherwise just read them out
    // of the raw API response regardless of what the teacher configured. The runner's own
    // position is never part of this payload at all (see e-CO.dc.html's own note on that).
    /** @param list<int> $validatedIds */
    private function formatCheckpointsForMap(EcoCourse $course, array $validatedIds): array
    {
        $checkpoints = $course->getParcours()->getCheckpoints()->toArray();
        usort($checkpoints, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        $nextId = null;
        foreach ($checkpoints as $checkpoint) {
            if (!\in_array($checkpoint->getId(), $validatedIds, true)) {
                $nextId = $checkpoint->getId();

                break;
            }
        }

        return array_map(function (EcoCheckpoint $checkpoint) use ($course, $validatedIds, $nextId): array {
            $isValidated = \in_array($checkpoint->getId(), $validatedIds, true);
            $isNext = $checkpoint->getId() === $nextId;

            $showCoordinates = match ($course->getMapVisibility()) {
                \App\Enum\EcoMapVisibility::AllCheckpoints => true,
                \App\Enum\EcoMapVisibility::ValidatedPlusNext => $isValidated || $isNext,
                \App\Enum\EcoMapVisibility::NextOnly => $isNext,
                \App\Enum\EcoMapVisibility::None => false,
            };

            return [
                'id' => $checkpoint->getId(),
                'shortCode' => $checkpoint->getShortCode(),
                'name' => $checkpoint->getName(),
                'position' => $checkpoint->getPosition(),
                'type' => $checkpoint->getType()->value,
                'latitude' => $showCoordinates ? $checkpoint->getLatitude() : null,
                'longitude' => $showCoordinates ? $checkpoint->getLongitude() : null,
            ];
        }, $checkpoints);
    }

    /** @return array{result: string, distanceMeters: ?float, toleranceMeters: int, attemptSequence: int, runnerStatus: string} */
    private function formatScan(EcoCheckpointScan $scan): array
    {
        return [
            'checkpointId' => $scan->getCheckpoint()->getId(),
            'result' => $scan->getResult()->value,
            'distanceMeters' => $scan->getDistanceMeters(),
            'toleranceMeters' => $scan->getCheckpoint()->getToleranceMeters(),
            'attemptSequence' => $scan->getAttemptSequence(),
            'runnerStatus' => $scan->getRunner()->getStatus()->value,
        ];
    }
}
