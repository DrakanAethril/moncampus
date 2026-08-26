<?php

declare(strict_types=1);

namespace App\Tests\Service\Survey;

use App\Service\FileLibraryTree;
use App\Service\Survey\SurveyFolderTree;
use PHPUnit\Framework\TestCase;

/**
 * The survey library's tree delegates its path arithmetic to the file library's - what is worth
 * pinning here is the one place it deliberately does **not**.
 *
 * `uniqueName()` has no extension rule: the file library puts the suffix before the extension
 * because "cours.pdf (2)" opens in nothing, and that same rule turns a folder named « Bilan 3.5 »
 * into « Bilan 3 (2).5 ». A folder has no extension, and this test is what keeps the two apart if
 * anybody ever "reuses" the other method.
 */
class SurveyFolderTreeTest extends TestCase
{
    private function tree(): SurveyFolderTree
    {
        return new SurveyFolderTree(new FileLibraryTree());
    }

    public function testAFreeNameIsLeftAlone(): void
    {
        self::assertSame('Satisfaction', $this->tree()->uniqueName('Satisfaction', ['Fin de stage', 'Rentrée']));
    }

    public function testATakenNameIsNumbered(): void
    {
        self::assertSame('Satisfaction (2)', $this->tree()->uniqueName('Satisfaction', ['Satisfaction']));
        self::assertSame('Satisfaction (3)', $this->tree()->uniqueName('Satisfaction', ['Satisfaction', 'Satisfaction (2)']));
    }

    public function testTheComparisonIgnoresCase(): void
    {
        self::assertSame('Satisfaction (2)', $this->tree()->uniqueName('Satisfaction', ['satisfaction']));
    }

    public function testADotInAFolderNameIsNotAnExtension(): void
    {
        self::assertSame('Bilan 3.5 (2)', $this->tree()->uniqueName('Bilan 3.5', ['Bilan 3.5']));
    }

    public function testTheSubtreeTestIsAnAncestorTestAndNotASubstringOne(): void
    {
        $tree = $this->tree();

        self::assertTrue($tree->isDescendantOf('/12/48/', 48));
        self::assertTrue($tree->isDescendantOf('/12/48/90/', 48));
        // '/12/480/' is not under 48 - the surrounding slashes are what says so.
        self::assertFalse($tree->isDescendantOf('/12/480/', 48));
    }

    public function testDepthCountsTheAncestors(): void
    {
        $tree = $this->tree();

        self::assertSame(0, $tree->depthOf('/'));
        self::assertSame(1, $tree->depthOf('/12/'));
        self::assertSame(2, $tree->depthOf('/12/48/'));
    }
}
