<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HelpSlug;
use App\Service\WikiTree;
use PHPUnit\Framework\TestCase;

/**
 * The three things the rail's shape depends on: a slug that is unique among its siblings, a
 * materialized path that answers "every descendant of X", and an assembly that orders by
 * (parent, position) rather than by path.
 *
 * Slug uniqueness is tested here rather than trusted to an index on purpose - MySQL treats every
 * NULL as distinct, so UNIQUE (wiki_id, parent_id, slug) enforces nothing at all at root level,
 * which is the level a user notices first.
 */
class WikiTreeTest extends TestCase
{
    private function tree(): WikiTree
    {
        return new WikiTree(new HelpSlug());
    }

    public function testSlugIsDerivedFromTheTitleAndFoldedToAscii(): void
    {
        // '&' is punctuation like any other to the shared slugger: it separates, it does not
        // become "et".
        self::assertSame('reseaux-securite', $this->tree()->uniqueSlug('Réseaux & Sécurité', []));
    }

    public function testSlugIsSuffixedUntilItIsFreeAmongItsSiblings(): void
    {
        $tree = $this->tree();

        self::assertSame('introduction', $tree->uniqueSlug('Introduction', ['autre']));
        self::assertSame('introduction-2', $tree->uniqueSlug('Introduction', ['introduction']));
        self::assertSame('introduction-3', $tree->uniqueSlug('Introduction', ['introduction', 'introduction-2']));
    }

    public function testATitleThatSlugsToNothingStillGetsAName(): void
    {
        // "???" folds to the empty string, and an empty slug would break the URL of the page.
        $tree = $this->tree();

        self::assertSame('page', $tree->uniqueSlug('???', []));
        self::assertSame('page-2', $tree->uniqueSlug('!!!', ['page']));
    }

    public function testChildPathAppendsTheParentToTheParentsOwnPath(): void
    {
        $tree = $this->tree();

        // A root node's path is '/' - it has no ancestor at all.
        self::assertSame('/', $tree->rootPath());
        self::assertSame('/12/', $tree->childPath('/', 12));
        self::assertSame('/12/48/', $tree->childPath('/12/', 48));
    }

    public function testDescendantsOfANodeAreItsPathPrefix(): void
    {
        $tree = $this->tree();

        // The one query the move rewrite and the subtree export both lean on.
        self::assertSame('/12/48/%', $tree->descendantPathPattern('/12/', 48));
    }

    public function testASubtreeMoveRewritesTheDescendantsPathPrefix(): void
    {
        $tree = $this->tree();

        self::assertSame('/7/48/93/', $tree->rewrittenPath('/12/48/93/', '/12/48/', '/7/48/'));
        // Only the prefix moves: a path that does not start with the old prefix is left alone.
        self::assertSame('/99/48/', $tree->rewrittenPath('/99/48/', '/12/48/', '/7/48/'));
    }

    public function testANodeCannotBeMovedIntoItsOwnSubtree(): void
    {
        $tree = $this->tree();

        // Target 93 sits under 48; moving 48 there would detach the whole branch from the wiki.
        self::assertTrue($tree->isDescendantOf('/12/48/', 48));
        self::assertTrue($tree->isDescendantOf('/12/48/93/', 48));
        self::assertFalse($tree->isDescendantOf('/12/', 48));
        // '/12/480/' must not read as a descendant of 48 - the separators are what make the
        // prefix test exact rather than a substring match.
        self::assertFalse($tree->isDescendantOf('/12/480/', 48));
    }

    public function testAssembleOrdersSiblingsByPositionAndNestsByParent(): void
    {
        $rows = [
            ['id' => 3, 'parentId' => 1, 'position' => 2, 'title' => 'VLAN'],
            ['id' => 1, 'parentId' => null, 'position' => 2, 'title' => 'Réseaux'],
            ['id' => 2, 'parentId' => null, 'position' => 1, 'title' => 'Introduction'],
            ['id' => 4, 'parentId' => 1, 'position' => 1, 'title' => 'Adressage'],
        ];

        $assembled = $this->tree()->assemble($rows);

        self::assertSame([2, 1], array_column($assembled, 'id'));
        self::assertSame([], $assembled[0]['children']);
        self::assertSame([4, 3], array_column($assembled[1]['children'], 'id'));
    }

    public function testAssembleDropsABranchWhoseParentIsMissing(): void
    {
        // A row whose parent was filtered out (deleted, or outside this wiki) must not silently
        // surface at root level, where it would read as a top-level page it is not.
        $assembled = $this->tree()->assemble([
            ['id' => 1, 'parentId' => null, 'position' => 1, 'title' => 'Accueil'],
            ['id' => 9, 'parentId' => 42, 'position' => 1, 'title' => 'Orpheline'],
        ]);

        self::assertSame([1], array_column($assembled, 'id'));
    }

    public function testNextPositionIsOneAboveTheHighestSibling(): void
    {
        $tree = $this->tree();

        self::assertSame(1, $tree->nextPosition([]));
        self::assertSame(4, $tree->nextPosition([1, 3, 2]));
    }
}
