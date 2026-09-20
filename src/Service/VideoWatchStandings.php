<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Entity\VideoWatchProgress;
use App\Repository\VideoWatchProgressRepository;

/**
 * Reads App\Service\VideoWatchStanding for a whole audience, in one query for the lot - the class
 * is thirty rows and a query per row is what this replaced.
 *
 * Nothing is stored: a standing is a reading of video_watch_progress against the files of the
 * moment, so adding or removing a file changes the figures with nothing to recompute.
 */
class VideoWatchStandings
{
    public function __construct(private readonly VideoWatchProgressRepository $progressRepository)
    {
    }

    /**
     * @param list<User> $audience
     *
     * @return array<int, VideoWatchStanding> student id => standing, one entry per member of the audience
     */
    public function forAudience(VideoResource $resource, array $audience): array
    {
        $files = array_values($resource->getFiles()->toArray());
        $progressByStudentId = $this->progressRepository->findByStudentAndFileForResource($resource);

        $standings = [];
        foreach ($audience as $student) {
            $standings[(int) $student->getId()] = $this->build($files, $progressByStudentId[(int) $student->getId()] ?? []);
        }

        return $standings;
    }

    /**
     * @param list<VideoResourceFile>          $files
     * @param array<int, VideoWatchProgress>   $progressByFileId
     */
    public function build(array $files, array $progressByFileId): VideoWatchStanding
    {
        $percents = [];
        $starts = [];
        $lasts = [];
        $completions = [];
        $watchedSeconds = 0;
        $skipCount = 0;
        $focusLossCount = 0;

        foreach ($files as $file) {
            $progress = $progressByFileId[(int) $file->getId()] ?? null;
            $percents[(int) $file->getId()] = $progress?->getMaxWatchedPercent() ?? 0;

            if (null !== $progress?->getFirstWatchedAt()) {
                $starts[] = $progress->getFirstWatchedAt();
            }
            if (null !== $progress?->getLastWatchedAt()) {
                $lasts[] = $progress->getLastWatchedAt();
            }

            $completions[] = $progress?->getCompletedAt();
            $watchedSeconds += $progress?->getWatchedSeconds() ?? 0;
            $skipCount += $progress?->getSkipCount() ?? 0;
            $focusLossCount += $progress?->getFocusLossCount() ?? 0;
        }

        $completions = array_values(array_filter($completions));

        // The set is finished when its last file is, and only then - the date is that last one.
        $complete = [] !== $percents && [] === array_filter($percents, static fn (int $percent): bool => $percent < 100);

        return new VideoWatchStanding(
            $percents,
            $this->weightedPercent($files, $percents),
            // The first playback of the set, which is what « commencé le » names. Rows written
            // before the column existed carry no first date; the last one stands in there, as the
            // readers of $completedAt already do.
            [] !== $starts ? min($starts) : ([] === $lasts ? null : min($lasts)),
            [] === $lasts ? null : max($lasts),
            $complete && [] !== $completions ? max($completions) : null,
            $watchedSeconds,
            $skipCount,
            $focusLossCount,
        );
    }

    /**
     * @param list<VideoResourceFile> $files
     * @param array<int, int>         $percents
     */
    private function weightedPercent(array $files, array $percents): int
    {
        $total = 0;
        $watched = 0.0;

        foreach ($files as $file) {
            $total += $file->getDurationSeconds();
            $watched += $file->getDurationSeconds() * (($percents[(int) $file->getId()] ?? 0) / 100);
        }

        return 0 === $total ? 0 : (int) floor($watched / $total * 100);
    }
}
