<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileLibraryTree;
use App\Service\LibraryPickerTree;
use PHPUnit\Framework\TestCase;

/**
 * The tree a picker modal browses: the library's folders, each carrying the items filed in it.
 *
 * Two things are the picker's own and are what this tests. A folder holding nothing and leading to
 * nothing must not be offered - the modal is there to *find* something, and an empty branch is a
 * dead end a reader has to open to discover. And an item whose folder is not in the set given is
 * filed at the root rather than dropped: the caller decides which folders it hands over (the owner's
 * own), and losing a quiz because of that decision would be a disappearance nothing announces.
 */
class LibraryPickerTreeTest extends TestCase
{
    private LibraryPickerTree $picker;

    protected function setUp(): void
    {
        $this->picker = new LibraryPickerTree(new FileLibraryTree());
    }

    public function testItemsAreFiledUnderTheirFolder(): void
    {
        $tree = $this->picker->build(
            [['id' => 1, 'parentId' => null, 'name' => 'Réseaux']],
            [['id' => 10, 'folderId' => 1, 'label' => 'TCP/IP', 'count' => 12]],
        );

        self::assertSame([], $tree['items']);
        self::assertCount(1, $tree['folders']);
        self::assertSame('Réseaux', $tree['folders'][0]['name']);
        self::assertSame([['id' => 10, 'folderId' => 1, 'label' => 'TCP/IP', 'count' => 12]], $tree['folders'][0]['items']);
    }

    public function testAnItemWithNoFolderSitsAtTheRoot(): void
    {
        $tree = $this->picker->build(
            [['id' => 1, 'parentId' => null, 'name' => 'Réseaux']],
            [['id' => 10, 'folderId' => null, 'label' => 'Révisions', 'count' => 3]],
        );

        self::assertSame([10], array_column($tree['items'], 'id'));
        // And the folder it is not in leads to nothing, so it is not offered at all.
        self::assertSame([], $tree['folders']);
    }

    public function testAnItemWhoseFolderIsNotOnOfferSitsAtTheRootRatherThanDisappearing(): void
    {
        $tree = $this->picker->build(
            [],
            [['id' => 10, 'folderId' => 77, 'label' => 'Quiz égaré', 'count' => 5]],
        );

        self::assertSame([10], array_column($tree['items'], 'id'));
    }

    public function testItemsAreOrderedByLabelIgnoringCase(): void
    {
        $tree = $this->picker->build(
            [],
            [
                ['id' => 1, 'folderId' => null, 'label' => 'ospf', 'count' => 1],
                ['id' => 2, 'folderId' => null, 'label' => 'Adressage', 'count' => 1],
                ['id' => 3, 'folderId' => null, 'label' => 'BGP', 'count' => 1],
            ],
        );

        self::assertSame(['Adressage', 'BGP', 'ospf'], array_column($tree['items'], 'label'));
    }

    public function testAFolderThatLeadsToNothingIsNotOffered(): void
    {
        $tree = $this->picker->build(
            [
                ['id' => 1, 'parentId' => null, 'name' => 'Vide'],
                ['id' => 2, 'parentId' => null, 'name' => 'Pleine'],
            ],
            [['id' => 10, 'folderId' => 2, 'label' => 'TCP/IP', 'count' => 12]],
        );

        self::assertSame(['Pleine'], array_column($tree['folders'], 'name'));
    }

    public function testAnEmptyFolderIsKeptWhenADescendantHoldsSomething(): void
    {
        // The branch is how one reaches the item: hiding the parent would hide the child with it.
        $tree = $this->picker->build(
            [
                ['id' => 1, 'parentId' => null, 'name' => 'BTS SIO'],
                ['id' => 2, 'parentId' => 1, 'name' => 'SISR'],
            ],
            [['id' => 10, 'folderId' => 2, 'label' => 'VLAN', 'count' => 4]],
        );

        self::assertCount(1, $tree['folders']);
        self::assertSame([], $tree['folders'][0]['items']);

        /** @var list<array{name: string, items: list<array{id: int}>}> $children */
        $children = $tree['folders'][0]['children'];
        $child = $children[0];
        self::assertSame('SISR', $child['name']);
        self::assertSame([10], array_column($child['items'], 'id'));
    }

    public function testAnEmptyLibraryYieldsAnEmptyTree(): void
    {
        self::assertSame(['folders' => [], 'items' => []], $this->picker->build([], []));
    }
}
