<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassBoard;

use App\Service\ClassBoard\CommonsPhoto;
use PHPUnit\Framework\TestCase;

/**
 * What may become a board's photograph of the day: a photograph (not a picture), wide enough for a
 * projector, landscape but not panorama, under a licence the board can honour by its credit line.
 */
class CommonsPhotoTest extends TestCase
{
    public function testAFeaturedLandscapeUnderCcBySaIsKeptWithItsCredit(): void
    {
        $photo = CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info());

        self::assertNotNull($photo);
        self::assertSame('Jane Doe', $photo->author);
        self::assertSame('CC BY-SA 4.0', $photo->licenseName);
        self::assertSame('https://creativecommons.org/licenses/by-sa/4.0', $photo->licenseUrl);
        self::assertSame('https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Lake.jpg/1920px-Lake.jpg', $photo->imageUrl);
        self::assertSame('https://commons.wikimedia.org/wiki/File:Lake.jpg', $photo->sourceUrl);
    }

    public function testAPictureWithoutACameraIsNotAPhotograph(): void
    {
        self::assertNull(CommonsPhoto::fromImageInfo('File:Painting.jpg', $this->info(['commonmetadata' => [['name' => 'Software', 'value' => 'GIMP']]])));
    }

    public function testOnlyAJpegIsTaken(): void
    {
        self::assertNull(CommonsPhoto::fromImageInfo('File:Render.png', $this->info(['mime' => 'image/png'])));
    }

    public function testPortraitsPanoramasAndSmallFilesAreLeftOut(): void
    {
        self::assertNull(CommonsPhoto::fromImageInfo('File:Tall.jpg', $this->info(['width' => 3000, 'height' => 4000])));
        self::assertNull(CommonsPhoto::fromImageInfo('File:360.jpg', $this->info(['width' => 12000, 'height' => 2000])));
        self::assertNull(CommonsPhoto::fromImageInfo('File:Small.jpg', $this->info(['width' => 1600, 'height' => 1000])));
        self::assertNotNull(CommonsPhoto::fromImageInfo('File:FourThirds.jpg', $this->info(['width' => 4000, 'height' => 3000])));
    }

    public function testALicenceTheBoardCannotHonourIsSkipped(): void
    {
        foreach (['CC BY-NC-SA 4.0', 'CC BY-ND 3.0', 'GFDL', 'Attribution only license'] as $license) {
            self::assertNull(CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info(['license' => $license])), $license);
        }
        foreach (['CC0', 'Public domain', 'CC BY 2.0', 'CC BY-SA 3.0 at'] as $license) {
            self::assertNotNull(CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info(['license' => $license])), $license);
        }
    }

    public function testACreditIsRequiredUnlessThePhotographIsPublicDomain(): void
    {
        self::assertNull(CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info(['artist' => ''])));

        $photo = CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info(['artist' => '', 'license' => 'CC0']));
        self::assertNotNull($photo);
        self::assertSame('Auteur inconnu', $photo->author);
    }

    public function testAnImageServedFromElsewhereIsRefused(): void
    {
        self::assertNull(CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info(['thumburl' => 'https://example.org/lake.jpg'])));
        self::assertNull(CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info(['thumburl' => 'https://thumb.wikimedia.org.example.org/lake.jpg'])));
        self::assertNotNull(CommonsPhoto::fromImageInfo('File:Lake.jpg', $this->info(['thumburl' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/a/ab/Lake.jpg/1920px-Lake.jpg?utm_source=commons.wikimedia.org'])));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function info(array $overrides = []): array
    {
        return [
            'mime' => $overrides['mime'] ?? 'image/jpeg',
            'width' => $overrides['width'] ?? 6000,
            'height' => $overrides['height'] ?? 4000,
            'thumburl' => $overrides['thumburl'] ?? 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Lake.jpg/1920px-Lake.jpg',
            'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Lake.jpg',
            'extmetadata' => [
                'LicenseShortName' => ['value' => $overrides['license'] ?? 'CC BY-SA 4.0'],
                'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0'],
                'Artist' => ['value' => $overrides['artist'] ?? '<a href="//commons.wikimedia.org/wiki/User:Jane">Jane&nbsp;Doe</a>'],
            ],
            'commonmetadata' => $overrides['commonmetadata'] ?? [['name' => 'Make', 'value' => 'Canon'], ['name' => 'Model', 'value' => 'Canon EOS 5D Mark II']],
        ];
    }
}
