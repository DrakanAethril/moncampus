<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Entity\VideoWatchProgress;
use App\Repository\VideoWatchProgressRepository;
use App\Service\VideoWatchStanding;
use App\Service\VideoWatchStandings;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * The one reading of « where is this student in this video », now shared by the tool's « Suivi de
 * visionnage » and the travail's follow-up. What is tested is what the two screens print off it:
 * the three states, the weighted percentage, and the two dates - the first playback and the moment
 * the set was through.
 */
class VideoWatchStandingsTest extends TestCase
{
    private const int LECTURE_ID = 1;
    private const int OUTRO_ID = 2;

    /** A twelve-minute lecture and a thirty-second outro are not half the set each. */
    public function testThePercentIsWeightedByRunningTime(): void
    {
        $standing = $this->standing([self::LECTURE_ID => 100, self::OUTRO_ID => 0]);

        self::assertSame(96, $standing->percent);
        self::assertSame(VideoWatchStanding::IN_PROGRESS, $standing->status());
    }

    /** A single second played opens the middle state - that is the whole point of having one. */
    public function testAnythingPlayedIsInProgressRatherThanNotStarted(): void
    {
        $standing = $this->standing([self::LECTURE_ID => 3]);

        self::assertTrue($standing->hasStarted());
        self::assertFalse($standing->isComplete());
        self::assertSame(VideoWatchStanding::IN_PROGRESS, $standing->status());
    }

    public function testNothingPlayedIsNotStarted(): void
    {
        $standing = $this->standing([]);

        self::assertSame(VideoWatchStanding::NOT_STARTED, $standing->status());
        self::assertSame(0, $standing->percent);
        self::assertNull($standing->startedAt);
    }

    /** « Terminé » only at 100 % on every file: one file left is one file left. */
    public function testOneFileShortIsNotComplete(): void
    {
        $standing = $this->standing([self::LECTURE_ID => 100, self::OUTRO_ID => 40]);

        self::assertFalse($standing->isComplete());
        self::assertNull($standing->completedAt);
    }

    public function testACompletedSetIsDatedOnItsLastFile(): void
    {
        $standing = $this->standing(
            [self::LECTURE_ID => 100, self::OUTRO_ID => 100],
            completions: [self::LECTURE_ID => '2026-09-05 18:40', self::OUTRO_ID => '2026-09-06 21:05'],
        );

        self::assertTrue($standing->isComplete());
        self::assertSame('2026-09-06 21:05', $standing->completedAt?->format('Y-m-d H:i'));
    }

    /** « Commencé le » is the first playback of the set, whichever file it was. */
    public function testStartedAtIsTheEarliestFirstPlayback(): void
    {
        $standing = $this->standing(
            [self::LECTURE_ID => 20, self::OUTRO_ID => 100],
            firstWatched: [self::LECTURE_ID => '2026-09-05 18:20', self::OUTRO_ID => '2026-09-04 09:10'],
        );

        self::assertSame('2026-09-04 09:10', $standing->startedAt?->format('Y-m-d H:i'));
    }

    /** Rows written before the column existed carry no first date; the last one stands in. */
    public function testARowWithoutAFirstDateFallsBackOnItsLastWatching(): void
    {
        $standing = $this->standing(
            [self::LECTURE_ID => 50],
            lastWatched: [self::LECTURE_ID => '2026-09-02 11:00'],
        );

        self::assertSame('2026-09-02 11:00', $standing->startedAt?->format('Y-m-d H:i'));
    }

    public function testAnAudienceIsReadInOneGoAndKeyedByStudent(): void
    {
        $marie = $this->user(10);
        $paul = $this->user(11);

        $repository = $this->createStub(VideoWatchProgressRepository::class);
        $repository->method('findByStudentAndFileForResource')->willReturn([
            10 => [self::LECTURE_ID => $this->progress(100), self::OUTRO_ID => $this->progress(100)],
        ]);

        $standings = (new VideoWatchStandings($repository))->forAudience($this->resource(), [$marie, $paul]);

        self::assertSame(VideoWatchStanding::COMPLETE, $standings[10]->status());
        // Never watched at all, and still a line: the table prints one row per student, not one per
        // row of video_watch_progress.
        self::assertSame(VideoWatchStanding::NOT_STARTED, $standings[11]->status());
    }

    /**
     * @param array<int, int>    $percentByFileId
     * @param array<int, string> $firstWatched
     * @param array<int, string> $lastWatched
     * @param array<int, string> $completions
     */
    private function standing(array $percentByFileId, array $firstWatched = [], array $lastWatched = [], array $completions = []): VideoWatchStanding
    {
        $progressByFileId = [];
        foreach ($percentByFileId as $fileId => $percent) {
            $progressByFileId[$fileId] = $this->progress(
                $percent,
                $firstWatched[$fileId] ?? null,
                $lastWatched[$fileId] ?? null,
                $completions[$fileId] ?? null,
            );
        }

        $repository = $this->createStub(VideoWatchProgressRepository::class);

        return (new VideoWatchStandings($repository))->build(
            [$this->file(self::LECTURE_ID, 720), $this->file(self::OUTRO_ID, 30)],
            $progressByFileId,
        );
    }

    private function progress(int $percent, ?string $firstWatchedAt = null, ?string $lastWatchedAt = null, ?string $completedAt = null): VideoWatchProgress
    {
        $progress = $this->createStub(VideoWatchProgress::class);
        $progress->method('getMaxWatchedPercent')->willReturn($percent);
        $progress->method('isComplete')->willReturn(100 <= $percent);
        $progress->method('getFirstWatchedAt')->willReturn(null === $firstWatchedAt ? null : new \DateTimeImmutable($firstWatchedAt));
        $progress->method('getLastWatchedAt')->willReturn(null === $lastWatchedAt ? null : new \DateTimeImmutable($lastWatchedAt));
        $progress->method('getCompletedAt')->willReturn(null === $completedAt ? null : new \DateTimeImmutable($completedAt));

        return $progress;
    }

    private function file(int $id, int $durationSeconds): VideoResourceFile
    {
        $file = $this->createStub(VideoResourceFile::class);
        $file->method('getId')->willReturn($id);
        $file->method('getDurationSeconds')->willReturn($durationSeconds);

        return $file;
    }

    private function resource(): VideoResource
    {
        $resource = $this->createStub(VideoResource::class);
        $resource->method('getFiles')->willReturn(new ArrayCollection([
            $this->file(self::LECTURE_ID, 720),
            $this->file(self::OUTRO_ID, 30),
        ]));

        return $resource;
    }

    private function user(int $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
