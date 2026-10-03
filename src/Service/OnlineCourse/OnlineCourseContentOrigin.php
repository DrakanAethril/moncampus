<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseMaterialRevision;
use App\Service\FileUploadService;

/**
 * Where a course material's bytes are read from: the CDN, for every nature
 * (design/validated/cours-en-ligne.md, §6). The application serves no byte of a material.
 *
 * For an interactive course the CDN is more than a faster road - **it is the isolation**. A
 * course's JavaScript runs on that origin, so it can read neither the session, nor the page, nor
 * the cookies of the platform. That only holds while the two origins differ, which is a property
 * of the deployment and not of the code: isIsolatedFrom() is the guard the player asks before it
 * draws a frame, and it refuses rather than trusts.
 */
class OnlineCourseContentOrigin
{
    public function __construct(
        private readonly FileUploadService $fileUploads,
    ) {
    }

    public function url(OnlineCourseMaterialRevision $revision): string
    {
        return $this->fileUploads->url($revision->getStorageKey());
    }

    /** The course's picture, from the CDN like its materials; null when it has none. */
    public function imageUrl(OnlineCourse $course): ?string
    {
        $key = $course->getImageKey();

        return null === $key ? null : $this->fileUploads->url($key);
    }

    /**
     * Whether content served from there cannot act as the application - i.e. the two hosts differ.
     *
     * A relative address, or one that names no host, is the application's own origin: that is what
     * a deployment with neither a CDN nor a public bucket endpoint produces, and it must read as
     * « not isolated ».
     */
    public function isIsolatedFrom(string $applicationHost): bool
    {
        $host = parse_url($this->fileUploads->url('probe'), \PHP_URL_HOST);

        return \is_string($host) && '' !== $host && mb_strtolower($host) !== mb_strtolower($applicationHost);
    }
}
