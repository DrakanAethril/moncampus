<?php

declare(strict_types=1);

namespace App\Tests\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Service\Eco\EcoParcoursTerrainAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * The profile rules of a leg, and the stamp that tells an analysis from the parcours it no longer
 * describes.
 */
class EcoParcoursTerrainAnalyzerTest extends TestCase
{
    public function testTheModelsGrainIsNotAClimb(): void
    {
        // Half-metre ripples, then a real 12 m rise and a 5 m drop.
        $climb = EcoParcoursTerrainAnalyzer::climbOf([300.0, 300.4, 299.8, 300.3, 306.0, 312.0, 309.0, 307.0]);

        self::assertNotNull($climb);
        self::assertEqualsWithDelta(12.0, $climb['climb'], 0.01);
        self::assertEqualsWithDelta(5.0, $climb['descent'], 0.01);
        self::assertNull(EcoParcoursTerrainAnalyzer::climbOf([300.0, null]));
    }

    public function testTheSteepestTwentyMetresAreFoundUpOrDown(): void
    {
        // Sampled every 10 m: 1 m, then 6 m down over 20 m (30 %), then flat.
        $slope = EcoParcoursTerrainAnalyzer::maxSlopeOf([300.0, 301.0, 298.0, 295.0, 295.0], 10.0);

        self::assertSame(30.0, $slope);
        self::assertNull(EcoParcoursTerrainAnalyzer::maxSlopeOf([300.0], 10.0));
    }

    public function testThePairKeyIgnoresDirection(): void
    {
        self::assertSame('12-15', EcoParcoursTerrainAnalyzer::pairKey(15, 12));
        self::assertSame('12-15', EcoParcoursTerrainAnalyzer::pairKey(12, 15));
    }

    public function testAnAnalysisIsStaleOnceAFlagHasMovedButNotAfterAReScanOnTheSameSpot(): void
    {
        $parcours = new EcoParcours(new User('prof.eco'));
        $flag = new EcoCheckpoint($parcours);
        $parcours->addCheckpoint($flag);
        $flag->locate(45.85, 1.23, new \DateTimeImmutable());

        $parcours->recordTerrainAnalysis(['fingerprint' => $parcours->locationFingerprint(), 'routes' => ['1-2' => 820.0]], new \DateTimeImmutable());
        self::assertTrue($parcours->hasCurrentTerrainAnalysis());

        // Re-scanned a few centimetres away: same flag, same ground.
        $flag->locate(45.8500001, 1.2300001, new \DateTimeImmutable());
        self::assertTrue($parcours->hasCurrentTerrainAnalysis());

        $flag->locate(45.851, 1.23, new \DateTimeImmutable());
        self::assertFalse($parcours->hasCurrentTerrainAnalysis());
        self::assertNull($parcours->terrainRouteMeters('1-2'), 'a stale analysis answers nothing');
    }

    public function testRoutesAddedAfterwardsKeepTheOnesAlreadyKnown(): void
    {
        $parcours = new EcoParcours(new User('prof.eco'));
        $parcours->recordTerrainAnalysis(['fingerprint' => $parcours->locationFingerprint(), 'routes' => ['1-2' => 820.0]], new \DateTimeImmutable());

        $parcours->addTerrainRoutes(['1-3' => 1310.0, '2-3' => null]);

        self::assertSame(820.0, $parcours->terrainRouteMeters('1-2'));
        self::assertSame(1310.0, $parcours->terrainRouteMeters('1-3'));
        self::assertTrue($parcours->hasTerrainRoute('2-3'), 'asked, even if the router found no way');
        self::assertNull($parcours->terrainRouteMeters('2-3'));
        self::assertFalse($parcours->hasTerrainRoute('3-4'));
    }
}
