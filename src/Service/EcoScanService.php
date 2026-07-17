<?php

namespace App\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCheckpointScan;
use App\Entity\EcoRunner;
use App\Enum\EcoCheckpointType;
use App\Enum\EcoCourseMode;
use App\Enum\EcoRunnerStatus;
use App\Enum\EcoScanMethod;
use App\Enum\EcoScanResult;
use App\Repository\EcoCheckpointScanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The scan-validation rules described in design/design_campus_manager/README.md's "e-CO" section
 * ("Règles de scan") - shared by the mobile runner API (App\Controller\Api\EcoRunnerApiController)
 * so the same logic backs both a real QR scan and a manual short-code entry (EcoScanMethod).
 *
 * Order: a runner must scan Start before anything else counts, and Finish only once already
 * racing. In App\Enum\EcoCourseMode::ImposedOrder, a regular checkpoint scanned before its turn is
 * rejected as out-of-order regardless of GPS distance - FreeOrder/Score don't enforce sequence at
 * all (only Start-first/Finish-last, which every mode requires).
 */
class EcoScanService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EcoCheckpointScanRepository $scanRepository,
        private readonly EcoDistanceCalculator $distanceCalculator,
    ) {
    }

    public function scan(EcoRunner $runner, EcoCheckpoint $checkpoint, ?float $latitude, ?float $longitude, \DateTimeImmutable $scannedAt, EcoScanMethod $method): EcoCheckpointScan
    {
        $attemptSequence = $this->scanRepository->count(['runner' => $runner, 'checkpoint' => $checkpoint]) + 1;

        $distanceMeters = (null !== $latitude && null !== $longitude && $checkpoint->isLocated())
            ? $this->distanceCalculator->distanceMeters($latitude, $longitude, $checkpoint->getLatitude(), $checkpoint->getLongitude())
            : null;

        $result = $this->resolveResult($runner, $checkpoint, $distanceMeters);

        $scan = new EcoCheckpointScan($runner, $checkpoint);
        $scan->setScannedAt($scannedAt);
        $scan->setLatitude($latitude);
        $scan->setLongitude($longitude);
        $scan->setDistanceMeters($distanceMeters);
        $scan->setMethod($method);
        $scan->setResult($result);
        $scan->setAttemptSequence($attemptSequence);
        $this->entityManager->persist($scan);

        if (EcoScanResult::Success === $result) {
            $this->applyRaceStateTransition($runner, $checkpoint, $scannedAt);
        }

        if (null !== $latitude && null !== $longitude) {
            $runner->updateLastPosition($latitude, $longitude, $scannedAt);
        }

        $this->entityManager->flush();

        return $scan;
    }

    private function resolveResult(EcoRunner $runner, EcoCheckpoint $checkpoint, ?float $distanceMeters): EcoScanResult
    {
        if (EcoCheckpointType::Start === $checkpoint->getType()) {
            return $this->withinTolerance($checkpoint, $distanceMeters) ? EcoScanResult::Success : EcoScanResult::OutOfRange;
        }

        if (EcoRunnerStatus::NotStarted === $runner->getStatus()) {
            return EcoScanResult::OutOfOrder;
        }

        if (EcoCheckpointType::Finish === $checkpoint->getType()) {
            return $this->withinTolerance($checkpoint, $distanceMeters) ? EcoScanResult::Success : EcoScanResult::OutOfRange;
        }

        if (EcoCourseMode::ImposedOrder === $runner->getCourse()->getMode() && !$this->isNextExpected($runner, $checkpoint)) {
            return EcoScanResult::OutOfOrder;
        }

        return $this->withinTolerance($checkpoint, $distanceMeters) ? EcoScanResult::Success : EcoScanResult::OutOfRange;
    }

    private function withinTolerance(EcoCheckpoint $checkpoint, ?float $distanceMeters): bool
    {
        return null !== $distanceMeters && $distanceMeters <= $checkpoint->getToleranceMeters();
    }

    // The lowest-position checkpoint (Checkpoint or Finish) this runner hasn't already
    // successfully scanned - what Ordre imposé requires the next scan to match.
    private function isNextExpected(EcoRunner $runner, EcoCheckpoint $candidate): bool
    {
        $validatedPositions = array_map(
            static fn (EcoCheckpointScan $scan): int => $scan->getCheckpoint()->getPosition(),
            array_filter($runner->getScans()->toArray(), static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult()),
        );

        $remaining = array_filter(
            $runner->getCourse()->getParcours()->getCheckpoints()->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => EcoCheckpointType::Start !== $checkpoint->getType() && !\in_array($checkpoint->getPosition(), $validatedPositions, true),
        );
        usort($remaining, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        $expected = $remaining[0] ?? null;

        return null !== $expected && $expected->getId() === $candidate->getId();
    }

    private function applyRaceStateTransition(EcoRunner $runner, EcoCheckpoint $checkpoint, \DateTimeImmutable $scannedAt): void
    {
        if (EcoCheckpointType::Start === $checkpoint->getType() && EcoRunnerStatus::NotStarted === $runner->getStatus()) {
            $runner->setStatus(EcoRunnerStatus::Racing);
            $runner->setStartedAt($scannedAt);
        } elseif (EcoCheckpointType::Finish === $checkpoint->getType() && EcoRunnerStatus::Racing === $runner->getStatus()) {
            $runner->setStatus(EcoRunnerStatus::Finished);
            $runner->setFinishedAt($scannedAt);
        }
    }
}
