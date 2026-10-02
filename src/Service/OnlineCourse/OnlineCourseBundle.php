<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

/**
 * An interactive course's archive, read and found acceptable: the files it will unpack into, by
 * the path each will have next to `index.html`, with the name the archive knows it by.
 *
 * Built by App\Service\OnlineCourse\OnlineCourseBundleReader and by nothing else - holding one is
 * holding the proof that every rule of the reader passed.
 *
 * @phpstan-type BundleFile array{path: string, entry: string, size: int}
 */
final readonly class OnlineCourseBundle
{
    /**
     * @param list<BundleFile> $files `path` is relative to the course's folder, `entry` is the
     *                                name inside the archive
     */
    public function __construct(
        public array $files,
        public int $totalBytes,
    ) {
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_column($this->files, 'path');
    }
}
