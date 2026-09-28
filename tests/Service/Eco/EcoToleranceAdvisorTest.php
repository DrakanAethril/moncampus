<?php

declare(strict_types=1);

namespace App\Tests\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Service\Eco\EcoToleranceAdvisor;
use PHPUnit\Framework\TestCase;

/**
 * The radius a flag should have under its canopy - and the two cases where saying nothing is the
 * right advice: no reading, and a radius already wide enough.
 */
class EcoToleranceAdvisorTest extends TestCase
{
    public function testTheCanopyWidensTheRadiusInSteps(): void
    {
        $advisor = new EcoToleranceAdvisor();

        self::assertNull($advisor->recommended(null));
        self::assertSame(EcoCheckpoint::DEFAULT_TOLERANCE_METERS, $advisor->recommended(0.0));
        self::assertSame(25, $advisor->recommended(6.5));
        self::assertSame(30, $advisor->recommended(22.0));
    }

    public function testAdviceIsOnlyEverToWidenAndOnlyWhenNeeded(): void
    {
        $advisor = new EcoToleranceAdvisor();
        $checkpoint = $this->checkpoint(canopy: 18.0, tolerance: 20);

        self::assertSame(30, $advisor->adviceFor($checkpoint));

        // A teacher who already widened it past the advice is left alone.
        $checkpoint->setToleranceMeters(40);
        self::assertNull($advisor->adviceFor($checkpoint));

        // Open ground under a default radius: nothing to say.
        self::assertNull($advisor->adviceFor($this->checkpoint(canopy: 0.4, tolerance: 20)));
    }

    public function testMovingAFlagForgetsTheGroundItStoodOn(): void
    {
        $checkpoint = $this->checkpoint(canopy: 18.0, tolerance: 20);

        $checkpoint->locate(45.86, 1.24, new \DateTimeImmutable());

        self::assertNull($checkpoint->getCanopyHeight());
        self::assertNull($checkpoint->getTerrainReadAt());
        self::assertNull((new EcoToleranceAdvisor())->adviceFor($checkpoint));
    }

    private function checkpoint(float $canopy, int $tolerance): EcoCheckpoint
    {
        $checkpoint = new EcoCheckpoint(new EcoParcours(new User('prof.eco')));
        $checkpoint->locate(45.85, 1.23, new \DateTimeImmutable());
        $checkpoint->recordTerrain(300.0, $canopy, new \DateTimeImmutable());
        $checkpoint->setToleranceMeters($tolerance);

        return $checkpoint;
    }
}
