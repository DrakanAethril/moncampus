<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileLibraryTree;
use PHPUnit\Framework\TestCase;

/**
 * The materialized path, and the one rule no index can enforce.
 *
 * `uniqueName()` is the authority on sibling uniqueness precisely because MySQL treats every NULL as
 * distinct: UNIQUE (owner, parent, name) would constrain nothing at root level - the level a user
 * meets first. So it is worth its own test rather than trusted to the schema.
 */
class FileLibraryTreeTest extends TestCase
{
    private FileLibraryTree $tree;

    protected function setUp(): void
    {
        $this->tree = new FileLibraryTree();
    }

    public function testAFreeNameIsKeptAsItIs(): void
    {
        self::assertSame('cours.pdf', $this->tree->uniqueName('cours.pdf', ['tp.pdf', 'sujets.zip']));
    }

    public function testATakenNameIsNumberedBeforeItsExtension(): void
    {
        // "cours.pdf (2)" opens in nothing: the suffix goes where a file manager, a download and a
        // double-click all agree it goes.
        self::assertSame('cours (2).pdf', $this->tree->uniqueName('cours.pdf', ['cours.pdf']));
        self::assertSame('cours (3).pdf', $this->tree->uniqueName('cours.pdf', ['cours.pdf', 'cours (2).pdf']));
    }

    public function testUniquenessIgnoresCaseTheWayAReaderDoes(): void
    {
        // Two files called "Cours.pdf" and "cours.pdf" in one folder is a trap, not a feature.
        self::assertSame('Cours (2).pdf', $this->tree->uniqueName('Cours.pdf', ['cours.pdf']));
    }

    public function testANameWithNoExtensionIsStillNumbered(): void
    {
        self::assertSame('Réseaux (2)', $this->tree->uniqueName('Réseaux', ['Réseaux']));
    }

    public function testPathsAreBuiltAndMeasuredFromTheirAncestors(): void
    {
        self::assertSame('/', $this->tree->rootPath());
        self::assertSame('/12/', $this->tree->childPath('/', 12));
        self::assertSame('/12/48/', $this->tree->childPath('/12/', 48));

        self::assertSame(0, $this->tree->depthOf('/'));
        self::assertSame(1, $this->tree->depthOf('/12/'));
        self::assertSame(2, $this->tree->depthOf('/12/48/'));
    }

    public function testASubtreeMoveRewritesOnlyWhatWasUnderTheOldPrefix(): void
    {
        self::assertSame('/7/48/90/', $this->tree->rewrittenPath('/12/48/90/', '/12/48/', '/7/48/'));
        // Anything outside the moved branch comes back untouched, so the caller may run this over a
        // wider row set without harm.
        self::assertSame('/3/5/', $this->tree->rewrittenPath('/3/5/', '/12/48/', '/7/48/'));
    }

    public function testAFolderCannotBeRecognisedAsItsOwnDescendantByAccident(): void
    {
        self::assertTrue($this->tree->isDescendantOf('/12/48/', 48));
        self::assertTrue($this->tree->isDescendantOf('/12/48/90/', 12));
        // The surrounding slashes are what make this an ancestor test rather than a substring match:
        // '/12/480/' is not under 48.
        self::assertFalse($this->tree->isDescendantOf('/12/480/', 48));
    }

    public function testAssemblingNestsByParentAndSortsSiblingsByName(): void
    {
        $nested = $this->tree->assemble([
            ['id' => 2, 'parentId' => null, 'name' => 'Systèmes'],
            ['id' => 1, 'parentId' => null, 'name' => 'Cours'],
            ['id' => 3, 'parentId' => 1, 'name' => 'Réseaux'],
        ]);

        self::assertCount(2, $nested);
        self::assertSame('Cours', $nested[0]['name']);
        self::assertSame('Systèmes', $nested[1]['name']);
        self::assertSame('Réseaux', $nested[0]['children'][0]['name']);
    }

    public function testAnOrphanIsDroppedRatherThanSurfacedAtRootLevel(): void
    {
        // Which is what happens the moment the caller filters out the deleted nodes and a deleted
        // folder still has live children: showing them at root would read as a top-level folder they
        // are not.
        $nested = $this->tree->assemble([
            ['id' => 1, 'parentId' => null, 'name' => 'Cours'],
            ['id' => 9, 'parentId' => 404, 'name' => 'Orpheline'],
        ]);

        self::assertCount(1, $nested);
        self::assertSame('Cours', $nested[0]['name']);
    }
}
