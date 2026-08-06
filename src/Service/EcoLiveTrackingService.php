<?php

namespace App\Service;

use App\Entity\EcoCheckpointScan;
use App\Entity\EcoRunner;
use App\Enum\EcoRunnerStatus;
use App\Enum\EcoScanResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Safety-monitoring view logic shared by the web live screen (App\Controller\EcoCourseController,
 * 1h) and the teacher mobile app's equivalent (App\Controller\Api\EcoTeacherApiController, 4d) -
 * one severity ranking/stale-signal definition, read by both.
 */
class EcoLiveTrackingService
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    // "Immobile depuis N min" threshold (screen 1h/4d) - a runner whose last known position is
    // older than this while still Racing shows as a stale-signal alert, same as one who's actively
    // backgrounded the app (EcoRunner::$appLeftAt).
    private const int STALE_SIGNAL_SECONDS = 240;

    /**
     * @param list<EcoRunner> $runners
     *
     * @return list<EcoRunner>
     */
    public function sortedBySeverity(array $runners): array
    {
        usort($runners, fn (EcoRunner $a, EcoRunner $b): int => $this->severityRank($a) <=> $this->severityRank($b));

        return $runners;
    }

    // 0 = SOS, 1 = stale signal while racing (no position update in STALE_SIGNAL_SECONDS, or
    // currently backgrounded), 2 = racing normally, 3 = finished/not started.
    public function severityRank(EcoRunner $runner): int
    {
        if ($runner->isSosActive()) {
            return 0;
        }
        if (EcoRunnerStatus::Racing === $runner->getStatus() && $this->isStale($runner)) {
            return 1;
        }
        if (EcoRunnerStatus::Racing === $runner->getStatus()) {
            return 2;
        }

        return 3;
    }

    public function isStale(EcoRunner $runner): bool
    {
        if (null !== $runner->getAppLeftAt()) {
            return true;
        }

        $lastPositionAt = $runner->getLastPositionAt();
        if (null === $lastPositionAt) {
            return true;
        }

        return (new \DateTimeImmutable())->getTimestamp() - $lastPositionAt->getTimestamp() > self::STALE_SIGNAL_SECONDS;
    }

    /** @return array{id: int, pseudo: string, status: string, checkpointsValidated: int, checkpointsTotal: int, sosActive: bool, isStale: bool, lastSignalSeconds: ?int, appLeftSeconds: ?int, signalLabel: string, signalWarning: bool} */
    public function runnerLiveRow(EcoRunner $runner): array
    {
        $now = new \DateTimeImmutable();
        $lastPositionAt = $runner->getLastPositionAt();
        $appLeftAt = $runner->getAppLeftAt();

        $validatedCount = \count(array_unique(array_map(
            static fn (EcoCheckpointScan $scan): int => $scan->getCheckpoint()->getId(),
            array_filter($runner->getScans()->toArray(), static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult()),
        )));

        return [
            'id' => $runner->getId(),
            'pseudo' => $runner->getPseudo() ?? '',
            'status' => $runner->getStatus()->value,
            'checkpointsValidated' => $validatedCount,
            'checkpointsTotal' => $runner->getCourse()->getParcours()->getCheckpoints()->count(),
            'sosActive' => $runner->isSosActive(),
            'isStale' => $this->isStale($runner),
            // max(0, ...) - a runner's phone clock can drift slightly ahead of the server's, which
            // would otherwise show a nonsensical negative "seconds ago".
            'lastSignalSeconds' => $lastSignalSeconds = null !== $lastPositionAt ? max(0, $now->getTimestamp() - $lastPositionAt->getTimestamp()) : null,
            'appLeftSeconds' => $appLeftSeconds = null !== $appLeftAt ? max(0, $now->getTimestamp() - $appLeftAt->getTimestamp()) : null,
            // Written out here rather than in the template and again in the Stimulus controller
            // that refreshes the same cell every 10 s - the two used to word it differently, and
            // the JS one was hard-coded French.
            'signalLabel' => $this->signalLabel($lastSignalSeconds, $appLeftSeconds),
            'signalWarning' => null !== $appLeftSeconds,
        ];
    }

    /**
     * The "Dernier signal" cell: how long ago, in the largest unit that stays readable - seconds
     * under a minute, then minutes, then hours. A raw "il y a 1173 s" is technically right and
     * useless to a teacher watching the field.
     */
    public function signalLabel(?int $lastSignalSeconds, ?int $appLeftSeconds): string
    {
        if (null !== $appLeftSeconds) {
            return $this->translator->trans('ecoLiveAppLeftLabel', ['%delay%' => $this->elapsed($appLeftSeconds)]);
        }

        if (null === $lastSignalSeconds) {
            return '—';
        }

        return $this->translator->trans('ecoLiveLastSignalLabel', ['%delay%' => $this->elapsed($lastSignalSeconds)]);
    }

    private function elapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return $this->translator->trans('ecoLiveDelaySecondsLabel', ['%seconds%' => $seconds]);
        }

        if ($seconds < 3600) {
            return $this->translator->trans('ecoLiveDelayMinutesLabel', ['%minutes%' => intdiv($seconds, 60)]);
        }

        return $this->translator->trans('ecoLiveDelayHoursLabel', [
            '%hours%' => intdiv($seconds, 3600),
            '%minutes%' => intdiv($seconds % 3600, 60),
        ]);
    }
}
