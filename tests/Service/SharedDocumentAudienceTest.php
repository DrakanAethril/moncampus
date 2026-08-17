<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SharedDocumentAudience;
use PHPUnit\Framework\TestCase;

/**
 * The one rule of « préciser le ciblage »: options and modalities are two filters, they are
 * independent, and they **intersect**.
 *
 * Worth its own test because the tempting reading is the other one - a union, where each extra box
 * ticked widens the audience. That reading would make « les alternants de l'option SLAM »
 * inexpressible, and it is exactly the shape a reviewer would "simplify" this into.
 */
class SharedDocumentAudienceTest extends TestCase
{
    public function testNoFilterAtAllReachesTheWholeClass(): void
    {
        self::assertTrue(SharedDocumentAudience::matches([], [], [], []));
        self::assertTrue(SharedDocumentAudience::matches([], [], [7], [3]));
    }

    public function testAnOptionFilterKeepsOnlyTheStudentsHoldingOneOfThem(): void
    {
        self::assertTrue(SharedDocumentAudience::matches([7, 8], [], [8], []));
        self::assertFalse(SharedDocumentAudience::matches([7, 8], [], [9], []));
    }

    public function testAModalityFilterWorksTheSameWayAndOnItsOwn(): void
    {
        self::assertTrue(SharedDocumentAudience::matches([], [3], [], [3]));
        self::assertFalse(SharedDocumentAudience::matches([], [3], [7], []));
    }

    public function testBothFiltersIntersectRatherThanAddUp(): void
    {
        // The student holds the option but not the modality: outside the audience. A union would
        // let them in, and « les alternants de l'option SLAM » could then never be expressed.
        self::assertFalse(SharedDocumentAudience::matches([7], [3], [7], [4]));
        self::assertFalse(SharedDocumentAudience::matches([7], [3], [9], [3]));
        self::assertTrue(SharedDocumentAudience::matches([7], [3], [7, 9], [3]));
    }

    public function testAFilterNobodyMatchesReachesNobody(): void
    {
        // Not "everybody": an empty *student* side is the absence of a tag, not a wildcard.
        self::assertFalse(SharedDocumentAudience::matches([7], [], [], []));
    }
}
