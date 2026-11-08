<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\FixedAvatarFamily;
use App\Service\FixedAvatars;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

/**
 * The ménagerie is a hundred avatars - thirty monsters, forty animals, thirty pieces of nature - each
 * an identifier and a picture: the catalogue and the files of assets/images/menagerie/ name the same
 * hundred, since a stored identifier is all a guest or a profile keeps.
 */
final class FixedAvatarsTest extends TestCase
{
    private const string FOLDER = __DIR__.'/../../assets/images/menagerie';

    public function testItHoldsAHundredInThreeFamilies(): void
    {
        $families = array_count_values(array_map(static fn (array $avatar): string => $avatar['family']->value, $this->menagerie()->all()));

        self::assertSame([FixedAvatarFamily::Monster->value => 30, FixedAvatarFamily::Animal->value => 40, FixedAvatarFamily::Nature->value => 30], $families);
        self::assertSame([30, 40, 30], array_map(static fn (array $group): int => \count($group['avatars']), $this->menagerie()->byFamily()));
    }

    public function testEveryIdentifierHasItsPictureAndNoPictureIsLeftOver(): void
    {
        $files = array_map(static fn (string $path): string => basename($path, '.svg'), glob(self::FOLDER.'/*.svg') ?: []);
        sort($files);
        $ids = FixedAvatars::ids();
        sort($ids);

        self::assertSame($ids, $files);
    }

    public function testIdentifiersFitTheirColumnsAndNamesAreDistinct(): void
    {
        foreach (FixedAvatars::ids() as $id) {
            self::assertMatchesRegularExpression('/^[a-z][a-z-]{1,39}$/', $id);
        }
        $names = array_map(static fn (array $avatar): string => $avatar['name'], $this->menagerie()->all());
        self::assertCount(100, array_unique($names));
    }

    public function testEveryPictureIsASelfContainedSquareSvg(): void
    {
        foreach (FixedAvatars::ids() as $id) {
            $svg = (string) file_get_contents(self::FOLDER.'/'.$id.'.svg');
            self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">', $svg, $id);
            self::assertStringNotContainsString('<script', $svg, $id);
            self::assertStringNotContainsString('href=', $svg, $id);
            self::assertNotFalse(simplexml_load_string($svg), $id);
        }
    }

    public function testAnUnknownIdentifierHasNoPicture(): void
    {
        self::assertFalse(FixedAvatars::exists('licorne'));
        self::assertNull($this->menagerie()->url('licorne'));
        self::assertNull($this->menagerie()->url(null));
        self::assertSame('/assets/images/menagerie/goupil.svg', $this->menagerie()->url('goupil'));
        self::assertSame('Goupil', FixedAvatars::name('goupil'));
    }

    private function menagerie(): FixedAvatars
    {
        return new FixedAvatars(new Packages(new PathPackage('/assets', new EmptyVersionStrategy())));
    }
}
