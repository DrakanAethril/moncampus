<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlineCourse;

use App\Service\FileUploadService;
use App\Service\OnlineCourse\OnlineCourseContentOrigin;
use PHPUnit\Framework\TestCase;

/**
 * The guard an interactive course's frame is drawn behind: the CDN must be another host than the
 * application, since that difference is the whole isolation of a course's JavaScript.
 */
class OnlineCourseContentOriginTest extends TestCase
{
    public function testAnotherHostIsolates(): void
    {
        self::assertTrue($this->origin('https://d123.cloudfront.net/probe')->isIsolatedFrom('formatool.example'));
        self::assertTrue($this->origin('https://bucket.s3.eu-west-3.amazonaws.com/bucket/probe')->isIsolatedFrom('localhost'));
    }

    public function testTheApplicationsOwnHostDoesNot(): void
    {
        self::assertFalse($this->origin('https://formatool.example/probe')->isIsolatedFrom('formatool.example'));
        self::assertFalse($this->origin('https://FORMATOOL.example/probe')->isIsolatedFrom('formatool.EXAMPLE'));
    }

    public function testAnAddressWithNoHostIsTheApplicationItself(): void
    {
        // No CDN and no public bucket endpoint configured: the address is relative.
        self::assertFalse($this->origin('/bucket/probe')->isIsolatedFrom('formatool.example'));
        self::assertFalse($this->origin('')->isIsolatedFrom('formatool.example'));
    }

    private function origin(string $url): OnlineCourseContentOrigin
    {
        $uploads = $this->createStub(FileUploadService::class);
        $uploads->method('url')->willReturn($url);

        return new OnlineCourseContentOrigin($uploads);
    }
}
