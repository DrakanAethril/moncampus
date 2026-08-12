<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Entity\VideoWatchProgress;
use App\Repository\VideoWatchProgressRepository;
use App\Service\VideoWatchTracker;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The weighting rule of a video set's overall progress, which is the one piece of arithmetic here
 * that can be wrong in a way nobody notices.
 */
class VideoWatchTrackerTest extends TestCase
{
    private const int LECTURE_ID = 1;
    private const int OUTRO_ID = 2;

    /**
     * A twelve-minute lecture and a thirty-second outro: watching only the outro is not "half
     * done", which is exactly what a plain average of the two percentages would claim.
     */
    public function testOverallProgressIsWeightedByRunningTime(): void
    {
        $resource = $this->resource();
        $tracker = $this->tracker([self::LECTURE_ID => 0, self::OUTRO_ID => 100]);

        self::assertSame(4, $tracker->overallPercent($resource, new User('student')));
    }

    public function testWatchingTheLongFileIsWorthMost(): void
    {
        $resource = $this->resource();
        $tracker = $this->tracker([self::LECTURE_ID => 100, self::OUTRO_ID => 0]);

        self::assertSame(96, $tracker->overallPercent($resource, new User('student')));
    }

    public function testEverythingWatchedIsAHundred(): void
    {
        $resource = $this->resource();
        $tracker = $this->tracker([self::LECTURE_ID => 100, self::OUTRO_ID => 100]);

        self::assertSame(100, $tracker->overallPercent($resource, new User('student')));
    }

    public function testNothingWatchedIsZero(): void
    {
        $resource = $this->resource();
        $tracker = $this->tracker([]);

        self::assertSame(0, $tracker->overallPercent($resource, new User('student')));
    }

    /** A set whose files carry no duration must not divide by zero. */
    public function testAnEmptySetIsZeroRatherThanAnError(): void
    {
        $resource = $this->createStub(VideoResource::class);
        $resource->method('getTotalDurationSeconds')->willReturn(0);
        $resource->method('getFiles')->willReturn(new ArrayCollection());

        self::assertSame(0, $this->tracker([])->overallPercent($resource, new User('student')));
    }

    private function resource(): VideoResource
    {
        $lecture = $this->file(self::LECTURE_ID, 720);
        $outro = $this->file(self::OUTRO_ID, 30);

        $resource = $this->createStub(VideoResource::class);
        $resource->method('getTotalDurationSeconds')->willReturn(750);
        $resource->method('getFiles')->willReturn(new ArrayCollection([$lecture, $outro]));

        return $resource;
    }

    private function file(int $id, int $durationSeconds): VideoResourceFile
    {
        $file = $this->createStub(VideoResourceFile::class);
        $file->method('getId')->willReturn($id);
        $file->method('getDurationSeconds')->willReturn($durationSeconds);

        return $file;
    }

    /** @param array<int, int> $percentByFileId */
    private function tracker(array $percentByFileId): VideoWatchTracker
    {
        $rows = [];
        foreach ($percentByFileId as $fileId => $percent) {
            $progress = $this->createStub(VideoWatchProgress::class);
            $progress->method('getMaxWatchedPercent')->willReturn($percent);
            $rows[$fileId] = $progress;
        }

        $repository = $this->createStub(VideoWatchProgressRepository::class);
        $repository->method('findByFileIdForStudent')->willReturn($rows);

        return new VideoWatchTracker($repository, $this->createStub(EntityManagerInterface::class));
    }
}
