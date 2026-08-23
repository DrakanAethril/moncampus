<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileLibraryTree;
use App\Service\QuizFolderTree;
use PHPUnit\Framework\TestCase;

/**
 * The quiz library's tree delegates its path arithmetic to the file library's - what is worth
 * pinning here is the one place it deliberately does **not**.
 *
 * `uniqueName()` has no extension rule: the file library puts the suffix before the extension
 * because "cours.pdf (2)" opens in nothing, and that same rule turns a folder named « 3.5 Réseaux »
 * into « 3 (2).5 Réseaux ». A folder has no extension, and this test is what keeps the two apart if
 * anybody ever "reuses" the other method.
 */
class QuizFolderTreeTest extends TestCase
{
    private function tree(): QuizFolderTree
    {
        return new QuizFolderTree(new FileLibraryTree());
    }

    public function testAFreeNameIsLeftAlone(): void
    {
        self::assertSame('Réseaux', $this->tree()->uniqueName('Réseaux', ['Systèmes', 'Bases de données']));
    }

    public function testATakenNameIsNumbered(): void
    {
        self::assertSame('Réseaux (2)', $this->tree()->uniqueName('Réseaux', ['Réseaux']));
        self::assertSame('Réseaux (3)', $this->tree()->uniqueName('Réseaux', ['Réseaux', 'Réseaux (2)']));
    }

    public function testTheComparisonIgnoresCase(): void
    {
        self::assertSame('Réseaux (2)', $this->tree()->uniqueName('Réseaux', ['réseaux']));
    }

    public function testADotInAFolderNameIsNotAnExtension(): void
    {
        self::assertSame('3.5 Réseaux (2)', $this->tree()->uniqueName('3.5 Réseaux', ['3.5 Réseaux']));
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
