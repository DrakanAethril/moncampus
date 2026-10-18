<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame;

use App\SeriousGame\SeriousGameManifest;
use PHPUnit\Framework\TestCase;

/**
 * A game's own vocabulary, which Formatool's screens use when they speak of it: what a manifest
 * does not say is L'Observatoire's word, the first game, written before the key.
 */
class SeriousGameManifestTest extends TestCase
{
    public function testAManifestWithoutWordsSpeaksLikeLObservatoire(): void
    {
        $words = (new SeriousGameManifest([]))->words();

        self::assertSame(['étoile', 'étoiles', 'constellation', 'comète', 'élucidée', 'allumée'], [$words['unit'], $words['units'], $words['chapter'], $words['comet'], $words['cometSolved'], $words['lit']]);
        self::assertSame('éclatante', $words['brightness']['blazing']);
    }

    public function testAManifestNamesItsOwnWords(): void
    {
        $words = (new SeriousGameManifest(['words' => [
            'unit' => ['tablette', 'tablettes'],
            'comet' => ['lézarde', 'lézardes'],
            'comet_solved' => 'réparée',
            'lit' => 'tracée',
            'brightness' => ['blazing' => ['dorée', 'dorées']],
        ]]))->words();

        self::assertSame('tablettes', $words['units']);
        self::assertSame('lézardes', $words['comets']);
        self::assertSame('réparée', $words['cometSolved']);
        self::assertSame('tracée', $words['litPlural'], 'a single word serves both numbers');
        self::assertSame('dorée', $words['brightness']['blazing']);
        self::assertSame('nette', $words['brightness']['clear']);
    }
}
