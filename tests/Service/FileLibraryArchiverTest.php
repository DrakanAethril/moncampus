<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Repository\FileLibraryNodeRepository;
use App\Service\FileLibraryArchiver;
use App\Service\FileLibrarySubtree;
use App\Service\FileUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * « Télécharger » on a row that names a folder: the shape that comes back.
 *
 * The one thing worth pinning is the *paths*. The listing is flat, with a depth per row, so the
 * archive's tree exists only because the builder rebuilds it - and the case that breaks a naive
 * implementation is a folder followed by something shallower than it, where the open folder has to
 * be backed out of before the next entry is named.
 *
 *     Dossier partagé
 *        Archives/
 *          vieux.pdf
 *        Bilans/
 *          Trimestre 1/     <- empty: still a directory entry
 *          bilan.pdf
 *        note.txt           <- back at the root, after two levels
 */
class FileLibraryArchiverTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        $this->owner = new User('prof');
    }

    public function testTheArchiveKeepsTheTree(): void
    {
        $entries = $this->entriesOf($this->tree());

        $this->assertSame(
            [
                'Archives/',
                'Archives/vieux.pdf',
                'Bilans/',
                'Bilans/Trimestre 1/',
                'Bilans/bilan.pdf',
                'note.txt',
            ],
            array_keys($entries),
        );

        $this->assertSame('bytes of vieux.pdf', $entries['Archives/vieux.pdf']);
        $this->assertSame('bytes of note.txt', $entries['note.txt']);
    }

    public function testTheArchiveIsNamedAfterTheFolder(): void
    {
        $tree = $this->tree();
        $tree['root']->setName('Cours : TD/TP');

        $response = $this->respond($tree);

        // A name a student reads, so the folder's own - with the characters no filesystem accepts
        // replaced rather than the whole thing rewritten.
        $this->assertStringContainsString('filename=', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Cours - TD-TP.zip', (string) $response->headers->get('Content-Disposition'));
    }

    public function testAFileWithNoObjectBehindItIsSkipped(): void
    {
        $tree = $this->tree();
        $tree['note']->setStorageKey(null);

        $this->assertArrayNotHasKey('note.txt', $this->entriesOf($tree));
    }

    /**
     * @param array<string, FileLibraryNode> $tree
     *
     * @return array<string, string> entry name => its content ('' for a directory)
     */
    private function entriesOf(array $tree): array
    {
        $response = $this->respond($tree);
        $zip = new \ZipArchive();
        $zip->open((string) $response->getFile()->getRealPath());

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink((string) $response->getFile()->getRealPath());

        return $entries;
    }

    /** @param array<string, FileLibraryNode> $tree */
    private function respond(array $tree): BinaryFileResponse
    {
        $repository = $this->createStub(FileLibraryNodeRepository::class);
        $repository->method('findSubtree')->willReturn(array_values($tree));

        $files = $this->createStub(FileUploadService::class);
        $files->method('read')->willReturnCallback(static fn (string $key): string => 'bytes of '.basename($key));

        return (new FileLibraryArchiver(new FileLibrarySubtree($repository), $files))->respond($tree['root']);
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

        if (FileLibraryNodeType::File === $type) {
            $node->setStorageKey('library/'.$name);
        }

        // Doctrine assigns it; the walk is keyed on it, so the test has to.
        $reflection = new \ReflectionProperty(FileLibraryNode::class, 'id');
        $reflection->setValue($node, $id);

        return $node;
    }
}
