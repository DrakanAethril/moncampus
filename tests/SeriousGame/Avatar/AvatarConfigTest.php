<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Avatar;

use App\SeriousGame\Avatar\AvatarConfig;
use PHPUnit\Framework\TestCase;

/**
 * The avatar's settings as the server keeps them (design/validated/serious-games.md §7).
 */
class AvatarConfigTest extends TestCase
{
    public function testAnEmptyConfigIsTheDefaultAvatar(): void
    {
        self::assertSame(AvatarConfig::DEFAULTS, AvatarConfig::normalize([], []));
    }

    public function testNoGenderIsKeptAndTheOldOneBecomesAStartingPoint(): void
    {
        $config = AvatarConfig::normalize(['sex' => 'f'], []);

        self::assertSame('b', $config['start']);
        self::assertArrayNotHasKey('sex', $config);
    }

    public function testUnknownValuesFallBackOnTheDefaults(): void
    {
        $config = AvatarConfig::normalize(['hair' => 'chapeau', 'skin' => 9, 'beard' => 'oui', 'hat' => 'haut-de-forme', 'hairC' => 7], []);

        self::assertSame('court', $config['hair']);
        self::assertSame(2, $config['skin']);
        self::assertFalse($config['beard']);
        self::assertSame(7, $config['hairC']);
        self::assertArrayNotHasKey('hat', $config);
    }

    public function testAPinIsKeptOnlyWhenItIsHeld(): void
    {
        self::assertSame('pin-etoile', AvatarConfig::normalize(['pin' => 'pin-etoile'], ['pin-etoile'])['pin']);
        self::assertNull(AvatarConfig::normalize(['pin' => 'pin-etoile'], [])['pin']);
        self::assertTrue(AvatarConfig::asksForAPinNotHeld(['pin' => 'pin-etoile'], []));
        self::assertFalse(AvatarConfig::asksForAPinNotHeld(['pin' => null], []));
    }

    public function testTheCatalogueFollowsTheSharedDrawing(): void
    {
        $script = (string) file_get_contents(__DIR__.'/../../../public/serious-games/shared/avatar.js');

        foreach (AvatarConfig::CHOICES as $key => $values) {
            foreach ($values as $value) {
                self::assertStringContainsString("['".$value."'", $script, $key.' '.$value);
            }
        }
        self::assertStringContainsString('x: 85, y: 150, width: 30, height: 46', $script, 'the bare head carries its neck');
    }
}
