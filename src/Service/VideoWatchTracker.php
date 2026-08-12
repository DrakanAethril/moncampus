<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Entity\VideoWatchProgress;
use App\Repository\VideoWatchProgressRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single place watching is reported to, whichever player is used.
 *
 * The web screen and the mobile app both call in here, exactly as the two audio players lean on
 * AudioListenTracker and the two quiz screens on StudentWorkBoard. One rule, one entry point: a
 * second implementation is how the two players start disagreeing about what "watched" means.
 */
class VideoWatchTracker
{
    public function __construct(
        private readonly VideoWatchProgressRepository $progressRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return int the percentage kept once the ratchet has been applied */
    public function register(VideoResourceFile $file, User $student, int $percent): int
    {
        $progress = $this->progressRepository->findOneFor($file, $student);

        if (null === $progress) {
            $progress = new VideoWatchProgress($file, $student);
            $this->entityManager->persist($progress);
        }

        $progress->registerProgress($percent);
        $this->entityManager->flush();

        return $progress->getMaxWatchedPercent();
    }

    /**
     * What the student has watched of each file of a resource, keyed by file id - enough to paint
     * their progress bars, and enough to tell the player where to resume crediting from one session
     * to the next.
     *
     * @return array<int, int>
     */
    public function progressPercents(VideoResource $resource, User $student): array
    {
        $byFileId = $this->progressRepository->findByFileIdForStudent($resource, $student);
        $percents = [];

        foreach ($resource->getFiles() as $file) {
            // `?? null` before the nullsafe call, not `?->` alone: the nullsafe operator does not
            // suppress the "undefined array key" warning for a file never watched, and this app
            // promotes warnings to exceptions.
            $percents[(int) $file->getId()] = ($byFileId[(int) $file->getId()] ?? null)?->getMaxWatchedPercent() ?? 0;
        }

        return $percents;
    }

    /**
     * The overall percentage of a resource, weighted by each file's running time.
     *
     * Weighted rather than averaged: a set holding a twelve-minute lecture and a thirty-second
     * outro is not half watched when only the outro has been played, and a plain average would say
     * it is.
     */
    public function overallPercent(VideoResource $resource, User $student): int
    {
        $total = $resource->getTotalDurationSeconds();

        if (0 === $total) {
            return 0;
        }

        $percents = $this->progressPercents($resource, $student);
        $watchedSeconds = 0.0;

        foreach ($resource->getFiles() as $file) {
            $watchedSeconds += $file->getDurationSeconds() * (($percents[(int) $file->getId()] ?? 0) / 100);
        }

        return (int) floor($watchedSeconds / $total * 100);
    }

    /**
     * When the watching was completed: the moment the last file fell into place. Null while any is
     * still short of 100%, which is what decides the completion of a Watching assignment.
     */
    public function completedAt(VideoResource $resource, User $student): ?\DateTimeImmutable
    {
        $files = $resource->getFiles();

        if (0 === $files->count()) {
            return null;
        }

        $byFileId = $this->progressRepository->findByFileIdForStudent($resource, $student);
        $dates = [];

        foreach ($files as $file) {
            $progress = $byFileId[(int) $file->getId()] ?? null;
            if (null === $progress || !$progress->isComplete()) {
                return null;
            }

            $dates[] = $progress->getLastWatchedAt();
        }

        $dates = array_filter($dates);

        return [] === $dates ? null : max($dates);
    }
}
