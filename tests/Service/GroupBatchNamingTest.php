<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GroupBatchNaming;
use PHPUnit\Framework\TestCase;

/**
 * The rule that lets « Dupliquer » exist: two saved lots of the same teacher, on the same Program,
 * must never be spelled the same, because the chips banner is nothing but their names. Duplicating
 * without renaming used to be impossible (a re-save under an existing name replaced that lot), so
 * this is what replaces the old "same name = overwrite" behaviour now that updating is its own
 * explicit button.
 */
class GroupBatchNamingTest extends TestCase
{
    private GroupBatchNaming $naming;

    protected function setUp(): void
    {
        $this->naming = new GroupBatchNaming();
    }

    public function testAFreeNameIsLeftAlone(): void
    {
        self::assertSame('TP réseau', $this->naming->unique('TP réseau', ['Oral blanc']));
    }

    public function testATakenNameGetsACopyNumber(): void
    {
        self::assertSame('TP réseau (2)', $this->naming->unique('TP réseau', ['TP réseau']));
    }

    public function testTheNumberKeepsClimbingUntilItIsFree(): void
    {
        self::assertSame('TP réseau (4)', $this->naming->unique('TP réseau', ['TP réseau', 'TP réseau (2)', 'TP réseau (3)']));
    }

    public function testAGapIsReusedRatherThanSkipped(): void
    {
        // « (2) » was deleted since: nothing carries it, so the copy takes it back.
        self::assertSame('TP réseau (2)', $this->naming->unique('TP réseau', ['TP réseau', 'TP réseau (3)']));
    }

    public function testAlreadyNumberedNamesCountFromTheirOwnSuffix(): void
    {
        // Duplicating the copy: the name to make free is « TP réseau (2) » itself, not « TP réseau ».
        self::assertSame('TP réseau (2) (2)', $this->naming->unique('TP réseau (2)', ['TP réseau', 'TP réseau (2)']));
    }

    public function testComparisonIgnoresCaseAndSurroundingSpaces(): void
    {
        // Two chips reading « TP Réseau » and « tp réseau » are two chips nobody can tell apart.
        self::assertSame('tp réseau (2)', $this->naming->unique('  tp réseau  ', ['TP Réseau']));
    }

    public function testTheNameIsTrimmedEvenWhenItIsFree(): void
    {
        self::assertSame('TP réseau', $this->naming->unique('  TP réseau ', []));
    }
}
