<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCheckpointReport;
use App\Entity\EcoCheckpointScan;
use App\Entity\EcoRunner;
use App\Enum\EcoCheckpointType;
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
 * racing. In a race run in order (EcoCourse::isOrdered(): Ordre imposé, or « Balises spécifiques »
 * in order), a regular checkpoint scanned before its turn is rejected as out-of-order regardless of
 * GPS distance - the other races don't enforce sequence at all (only Start-first/Finish-last, which
 * every mode requires). « Next » is read over the race's own flags (EcoCourse::getRaceCheckpoints()):
 * a flag the course leaves out is never the one expected. The caller only ever hands in a flag of
 * the race - the runner API answers checkpointNotFound for any other.
 *
 * A flag missing from the terrain does not stop the race: the runner reports it (« Balise
 * introuvable », report()) and the next flag becomes the one expected for them; the teacher
 * neutralises it for everybody (EcoCourse::$neutralizedCheckpoints). Neither validates it.
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

        if ($runner->getCourse()->isOrdered() && !$this->isNextExpected($runner, $checkpoint)) {
            return EcoScanResult::OutOfOrder;
        }

        return $this->withinTolerance($checkpoint, $distanceMeters) ? EcoScanResult::Success : EcoScanResult::OutOfRange;
    }

    private function withinTolerance(EcoCheckpoint $checkpoint, ?float $distanceMeters): bool
    {
        return null !== $distanceMeters && $distanceMeters <= $checkpoint->getToleranceMeters();
    }

    // The first checkpoint of the race (Checkpoint or Finish), in the race's own order, this runner
    // hasn't already successfully scanned nor been let off - what a race run in order requires the
    // next scan to match. In « Balises spécifiques » that order is the teacher's, not the parcours'.
    // A flag reported missing by this runner, or neutralised by the teacher, is passed over: one
    // flag taken from the terrain otherwise refused every flag after it, to every runner.
    private function isNextExpected(EcoRunner $runner, EcoCheckpoint $candidate): bool
    {
        $expected = self::nextExpected($runner);

        return null !== $expected && $expected->getId() === $candidate->getId();
    }

    /** The flag a race run in order expects next from this runner; null once there is none left. */
    public static function nextExpected(EcoRunner $runner): ?EcoCheckpoint
    {
        $settled = [...$runner->validatedCheckpointIds(), ...$runner->passedOverCheckpointIds()];

        foreach ($runner->getCourse()->getRaceCheckpoints() as $checkpoint) {
            if (EcoCheckpointType::Start !== $checkpoint->getType() && !\in_array((int) $checkpoint->getId(), $settled, true)) {
                return $checkpoint;
            }
        }

        return null;
    }

    /**
     * « Balise introuvable »: the runner gives up on a regular flag of their race they have not
     * validated. Refused (null) for Départ and Arrivée - the race is timed between them - and for
     * a runner not on the course. Reporting a flag twice answers the first report.
     */
    public function report(EcoRunner $runner, EcoCheckpoint $checkpoint, ?float $latitude, ?float $longitude, \DateTimeImmutable $reportedAt): ?EcoCheckpointReport
    {
        if (EcoCheckpointType::Checkpoint !== $checkpoint->getType()
            || EcoRunnerStatus::Racing !== $runner->getStatus()
            || !$runner->getCourse()->hasRaceCheckpoint($checkpoint)
            || \in_array((int) $checkpoint->getId(), $runner->validatedCheckpointIds(), true)) {
            return null;
        }

        $existing = $runner->reportOf($checkpoint);
        if (null !== $existing) {
            return $existing;
        }

        $report = new EcoCheckpointReport($runner, $checkpoint, $reportedAt, $latitude, $longitude);
        $runner->addCheckpointReport($report);
        $this->entityManager->persist($report);

        if (null !== $latitude && null !== $longitude) {
            $runner->updateLastPosition($latitude, $longitude, $reportedAt);
        }

        $this->entityManager->flush();

        return $report;
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
