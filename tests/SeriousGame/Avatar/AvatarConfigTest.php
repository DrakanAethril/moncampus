<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Avatar;

use App\SeriousGame\Avatar\AvatarConfig;
use PHPUnit\Framework\TestCase;

/**
 * The avatar's settings as the server keeps them (design/validated/serious-games.md §7), with the
 * manga wardrobe of design/validated/avatar-manga.md.
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
        $config = AvatarConfig::normalize(['hair' => 'chapeau', 'skin' => 11, 'beard' => 'oui', 'hat' => 'haut-de-forme', 'hairC' => 7], []);

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

    public function testTheMangaChoicesAreKept(): void
    {
        $sent = [
            'face' => 'pointu', 'hair' => 'flamme', 'eyes' => 'manga', 'brows' => 'fronces', 'nose' => 'trait',
            'mouth' => 'croc', 'glasses' => 'demi', 'hairAcc' => 'ruban', 'mask' => 'noir', 'ears' => 'anneaux',
            'topCut' => 'marin', 'bgPat' => 'cosmos', 'aura' => 'feu', 'above' => 'halo',
        ];

        $config = AvatarConfig::normalize($sent, []);

        foreach ($sent as $key => $value) {
            self::assertSame($value, $config[$key], $key);
        }
    }

    public function testEveryNewKeyStartsNeutral(): void
    {
        $config = AvatarConfig::normalize([], []);

        self::assertSame('aucun', $config['hairAcc']);
        self::assertSame('aucun', $config['mask']);
        self::assertSame('aucune', $config['ears']);
        self::assertSame('rond', $config['topCut']);
        self::assertSame('uni', $config['bgPat']);
        self::assertSame('aucune', $config['aura']);
        self::assertSame('aucun', $config['above']);
        self::assertSame(-1, $config['hairC2']);
        foreach (['scar', 'bandage', 'mole', 'ahoge', 'reflet', 'hetero', 'patch', 'hachures', 'goutte', 'veine', 'larmes', 'ombre', 'deprime', 'glare', 'bulle', 'etincelles', 'electricite', 'confettis'] as $toggle) {
            self::assertFalse($config[$toggle], $toggle);
        }
    }

    public function testColoursAreAppendedSoAStoredIndexKeepsItsColour(): void
    {
        $config = AvatarConfig::normalize(['skin' => 10, 'hairC' => 16, 'iris' => 9, 'top' => 10, 'bg' => 15], []);
        self::assertSame([10, 16, 9, 10, 15], [$config['skin'], $config['hairC'], $config['iris'], $config['top'], $config['bg']]);

        self::assertSame(2, AvatarConfig::normalize(['skin' => 11], [])['skin']);
        self::assertSame(0, AvatarConfig::normalize(['bg' => 16], [])['bg']);
        self::assertSame(1, AvatarConfig::normalize(['hairC' => 17], [])['hairC']);
        self::assertSame(0, AvatarConfig::normalize(['iris' => 10], [])['iris']);
        self::assertSame(1, AvatarConfig::normalize(['top' => 11], [])['top']);
    }

    public function testTheTipsColourIsAHairColourOrNone(): void
    {
        self::assertSame(9, AvatarConfig::normalize(['hairC2' => 9], [])['hairC2']);
        self::assertSame(16, AvatarConfig::normalize(['hairC2' => 16], [])['hairC2']);
        self::assertSame(-1, AvatarConfig::normalize(['hairC2' => 17], [])['hairC2']);
        self::assertSame(-1, AvatarConfig::normalize(['hairC2' => -2], [])['hairC2']);
        self::assertSame(-1, AvatarConfig::normalize(['hairC2' => '3'], [])['hairC2']);
    }

    public function testTheOldEarringsBecomeStuds(): void
    {
        $config = AvatarConfig::normalize(['earrings' => true], []);

        self::assertSame('clous', $config['ears']);
        self::assertArrayNotHasKey('earrings', $config);
        self::assertArrayNotHasKey('earrings', AvatarConfig::DEFAULTS);
        self::assertSame('aucune', AvatarConfig::normalize(['earrings' => false], [])['ears']);
        self::assertSame('pendants', AvatarConfig::normalize(['earrings' => true, 'ears' => 'pendants'], [])['ears']);
    }

    public function testTheCatalogueFollowsTheSharedDrawing(): void
    {
        $script = (string) file_get_contents(__DIR__.'/../../../public/serious-games/shared/avatar.js');

        foreach (AvatarConfig::CHOICES as $key => $values) {
            foreach ($values as $value) {
                self::assertStringContainsString("['".$value."'", $script, $key.' '.$value);
            }
        }
        foreach (AvatarConfig::TOGGLES as $toggle) {
            self::assertStringContainsString("['".$toggle."'", $script, $toggle);
        }
        foreach (['SKIN' => 'skin', 'HAIR' => 'hairC', 'IRIS' => 'iris', 'TOP' => 'top', 'BG' => 'bg'] as $palette => $key) {
            self::assertSame(1, preg_match('/A\\.'.$palette.' = \\[(.*?)\\];/s', $script, $match), $palette);
            $count = 'SKIN' === $palette ? substr_count($match[1], "['#") : substr_count($match[1], "'#");
            self::assertSame(AvatarConfig::COLORS[$key], $count, $palette);
        }
        self::assertStringContainsString('A.VERSION = '.AvatarConfig::VERSION.';', $script);
        self::assertStringContainsString('x: 85, y: 150, width: 30, height: 46', $script, 'the bare head carries its neck');
    }
}
