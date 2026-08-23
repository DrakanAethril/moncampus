<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Repository\FileLibraryNodeRepository;
use App\Service\FileLibrarySubtree;
use PHPUnit\Framework\TestCase;

/**
 * The ordering a shared folder is read in, which is the only reason this service exists.
 *
 * The tree below is the case that made it necessary. Sorting the subtree by its materialised path -
 * which runs on identifiers - puts the children of « Bilans » (id 3) before those of « Archives »
 * (id 7), because 3 sorts before 7: two branches interleaved, with a file appearing under the wrong
 * folder. Walking the levels is what fixes it.
 *
 *     10 Dossier partagé
 *        7 Archives          <- alphabetically first, created last
 *          8 vieux.pdf
 *        3 Bilans
 *          4 bilan.pdf
 *          9 Trimestre 1     <- a folder among files: folders come first whatever the name
 *        5 note.txt
 */
class FileLibrarySubtreeTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        $this->owner = new User('prof');
    }

    public function testTheTreeIsWalkedLevelByLevel(): void
    {
        $this->assertSame(
            [
                'Archives 0',
                'vieux.pdf 1',
                'Bilans 0',
                'Trimestre 1 1',
                'bilan.pdf 1',
                'note.txt 0',
            ],
            $this->rows($this->tree()),
        );
    }

    public function testATrashedBranchIsNotListed(): void
    {
        $tree = $this->tree();

        // The whole branch carries its own deletedAt, set by the same operation that trashed the
        // folder - which is why skipping the folder is enough to skip what is under it.
        $tree['archives']->setDeletedAt(new \DateTimeImmutable());
        $tree['vieux']->setDeletedAt(new \DateTimeImmutable());

        $this->assertSame(['Bilans 0', 'Trimestre 1 1', 'bilan.pdf 1', 'note.txt 0'], $this->rows($tree));
    }

    public function testAnEmptyFolderListsNothing(): void
    {
        $root = $this->node(10, 'Dossier partagé', FileLibraryNodeType::Folder, '/', null);

        $this->assertSame([], $this->rows(['root' => $root]));
    }

    /**
     * @param array<string, FileLibraryNode> $tree
     *
     * @return list<string>
     */
    private function rows(array $tree): array
    {
        $root = $tree['root'];
        // A stub, not a mock: nothing here is about how the repository is called, only about
        // what the walk does with the rows it hands back.
        $repository = $this->createStub(FileLibraryNodeRepository::class);
        $repository->method('findSubtree')->willReturn(array_values($tree));

        return array_map(
            static fn (array $row): string => $row['node']->getName().' '.$row['depth'],
            (new FileLibrarySubtree($repository))->rows($root),
        );
    }

    /** @return array<string, FileLibraryNode> */
    private function tree(): array
    {
        $root = $this->node(10, 'Dossier partagé', FileLibraryNodeType::Folder, '/', null);
        $archives = $this->node(7, 'Archives', FileLibraryNodeType::Folder, '/10/', $root);
        $bilans = $this->node(3, 'Bilans', FileLibraryNodeType::Folder, '/10/', $root);

        return [
            'root' => $root,
            'archives' => $archives,
            'bilans' => $bilans,
            'note' => $this->node(5, 'note.txt', FileLibraryNodeType::File, '/10/', $root),
            'vieux' => $this->node(8, 'vieux.pdf', FileLibraryNodeType::File, '/10/7/', $archives),
            'bilan' => $this->node(4, 'bilan.pdf', FileLibraryNodeType::File, '/10/3/', $bilans),
            'trimestre' => $this->node(9, 'Trimestre 1', FileLibraryNodeType::Folder, '/10/3/', $bilans),
        ];
    }

    private function node(int $id, string $name, FileLibraryNodeType $type, string $path, ?FileLibraryNode $parent): FileLibraryNode
    {
        $node = new FileLibraryNode($this->owner, $type, $name);
        $node->setPath($path);
        $node->setParent($parent);

        // Doctrine assigns it; the walk is keyed on it, so the test has to.
        $reflection = new \ReflectionProperty(FileLibraryNode::class, 'id');
        $reflection->setValue($node, $id);

        return $node;
    }
}
