<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AccessConditionCycleDetector;
use PHPUnit\Framework\TestCase;

/**
 * "A déverrouillé par B déverrouillé par A" - cheap to write here, very expensive to discover in
 * production, where it reads as two objects nobody can ever open and no error anywhere.
 *
 * The walk is over node keys alone ("assignment:17"), so it is decided without a database: which
 * rows carry which edges is AccessConditionGraph's job.
 */
class AccessConditionCycleDetectorTest extends TestCase
{
    public function testAConditionPointingAtSomethingUnconditionalIsFine(): void
    {
        self::assertFalse($this->detector()->wouldCycle('assignment:1', ['assignment:2'], ['assignment:2' => []]));
    }

    /** The shortest cycle there is: an object conditioned on itself. */
    public function testAnObjectCannotUnlockItself(): void
    {
        self::assertTrue($this->detector()->wouldCycle('assignment:1', ['assignment:1'], []));
    }

    public function testTwoObjectsUnlockingEachOther(): void
    {
        self::assertTrue($this->detector()->wouldCycle('assignment:1', ['quiz_instance:9'], [
            'quiz_instance:9' => ['assignment:1'],
        ]));
    }

    public function testALongerLoopIsFoundToo(): void
    {
        self::assertTrue($this->detector()->wouldCycle('assignment:1', ['assignment:2'], [
            'assignment:2' => ['resource:3'],
            'resource:3' => ['quiz_instance:4'],
            'quiz_instance:4' => ['assignment:1'],
        ]));
    }

    /**
     * A diamond is not a cycle: two chains reaching the same object is an ordinary way to build a
     * course, and refusing it would refuse the feature.
     */
    public function testADiamondIsNotACycle(): void
    {
        self::assertFalse($this->detector()->wouldCycle('assignment:1', ['assignment:2', 'assignment:3'], [
            'assignment:2' => ['resource:9'],
            'assignment:3' => ['resource:9'],
            'resource:9' => [],
        ]));
    }

    /**
     * A cycle among objects the saved one merely depends on still has to be walked out of, or the
     * detector loops forever instead of answering.
     */
    public function testAPreexistingLoopUpstreamDoesNotHangTheWalk(): void
    {
        self::assertFalse($this->detector()->wouldCycle('assignment:1', ['assignment:2'], [
            'assignment:2' => ['assignment:3'],
            'assignment:3' => ['assignment:2'],
        ]));
    }

    private function detector(): AccessConditionCycleDetector
    {
        return new AccessConditionCycleDetector();
    }
}
