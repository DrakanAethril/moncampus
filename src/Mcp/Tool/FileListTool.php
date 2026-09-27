<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\FileLibraryNode;
use App\Entity\LessonLogAttachment;
use App\Entity\LibraryResource;
use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\FileLibraryNodeRepository;
use App\Security\Voter\FileLibraryVoter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The teacher's bibliothèque de fichiers - one folder, or a search across it - with, for each file,
 * the séquences, séances, phases and cahiers de texte it is attached to. That last part is what lets
 * Claude start from « the files of my VLAN séquence » as well as from a file nobody attached anywhere
 * yet.
 *
 * The attachments come from one query over App\Entity\LibraryResource and one over
 * App\Entity\LessonLogAttachment - the two kinds of attachment this connector writes. The other places a file can
 * serve (a travail, a class share, a wiki page…) are listed by file_read, one file at a time:
 * App\Service\FileLibraryLinks asks ten tables per file, which a folder of a hundred would multiply.
 */
final readonly class FileListTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private FileLibraryNodeRepository $nodes,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'file_list';
    }

    public function title(): string
    {
        return 'Lister mes fichiers';
    }

    public function description(): string
    {
        return 'Liste la bibliothèque de fichiers de l\'enseignant : le contenu d\'un dossier (folderId, la racine par défaut) ou une recherche par nom dans toute la bibliothèque (query). Chaque fichier indique les séquences, séances, phases et cahiers de texte (kind « lesson_log », id = sessionId) auxquels il est rattaché ; `linked: "unlinked"` ne garde que les fichiers rattachés nulle part, `"linked"` que ceux qui le sont. Renvoie aussi l\'arborescence des dossiers. Les fichiers se lisent avec file_read.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'folderId' => ['type' => 'integer', 'description' => 'Dossier à lister. Absent : la racine.'],
                'query' => ['type' => 'string', 'description' => 'Recherche par nom dans toute la bibliothèque (remplace folderId).'],
                'linked' => ['type' => 'string', 'enum' => ['any', 'linked', 'unlinked'], 'default' => 'any'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::FileLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $query = trim($call->arguments->string('query'));
        $folder = '' === $query ? $this->library->fileFolder($call->optionalId('folderId'), FileLibraryVoter::VIEW) : null;
        $linked = $call->arguments->string('linked', 'any');
        if (!\in_array($linked, ['any', 'linked', 'unlinked'], true)) {
            throw new McpToolException('L\'argument « linked » vaut « any », « linked » ou « unlinked ».');
        }

        $nodes = '' === $query ? $this->nodes->findChildren($call->user, $folder) : $this->nodes->search($call->user, $query);
        $files = array_values(array_filter($nodes, static fn (FileLibraryNode $node): bool => $node->isFile()));
        $attachments = $this->attachmentsOf($files);

        $rows = [];
        foreach ($files as $file) {
            $linkedTo = $attachments[(int) $file->getId()] ?? [];
            if (('linked' === $linked && [] === $linkedTo) || ('unlinked' === $linked && [] !== $linkedTo)) {
                continue;
            }

            $rows[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'mimeType' => $file->getMimeType(),
                'sizeBytes' => $file->getSizeBytes(),
                'folderId' => $file->getParent()?->getId(),
                'linkedTo' => $linkedTo,
            ];
        }

        $subfolders = array_values(array_filter($nodes, static fn (FileLibraryNode $node): bool => $node->isFolder()));

        return McpToolResult::data(
            \sprintf('%d fichiers%s.', \count($rows), '' === $query ? \sprintf(' dans « %s »', $folder?->getName() ?? 'la racine') : \sprintf(' trouvés pour « %s »', $query)),
            [
                'folder' => null === $folder ? null : ['id' => $folder->getId(), 'name' => $folder->getName(), 'url' => $this->links->fileFolder($folder)],
                'subfolders' => array_map(static fn (FileLibraryNode $node): array => ['id' => $node->getId(), 'name' => $node->getName()], $subfolders),
                'files' => $rows,
                'allFolders' => array_map(static fn (FileLibraryNode $node): array => ['id' => $node->getId(), 'name' => $node->getName(), 'parentId' => $node->getParent()?->getId()], $this->nodes->findFolders($call->user)),
            ],
        );
    }

    /**
     * @param list<FileLibraryNode> $files
     *
     * @return array<int, list<array{kind: string, id: ?int, title: ?string}>>
     */
    private function attachmentsOf(array $files): array
    {
        if ([] === $files) {
            return [];
        }

        /** @var list<LibraryResource> $resources */
        $resources = $this->entityManager->createQueryBuilder()
            ->select('r', 'sq', 'se', 'ph')
            ->from(LibraryResource::class, 'r')
            ->leftJoin('r.sequenceTemplate', 'sq')
            ->leftJoin('r.seanceTemplate', 'se')
            ->leftJoin('r.seancePhaseTemplate', 'ph')
            ->where('r.libraryNode IN (:files)')
            ->setParameter('files', $files)
            ->getQuery()
            ->getResult();

        $attachments = [];
        foreach ($resources as $resource) {
            $node = $resource->getLibraryNode();
            if (null === $node) {
                continue;
            }

            $attachments[(int) $node->getId()][] = match (true) {
                null !== $resource->getSeancePhaseTemplate() => ['kind' => 'phase', 'id' => $resource->getSeancePhaseTemplate()->getId(), 'title' => $resource->getSeancePhaseTemplate()->getNom()],
                null !== $resource->getSeanceTemplate() => ['kind' => 'seance', 'id' => $resource->getSeanceTemplate()->getId(), 'title' => $resource->getSeanceTemplate()->getTitre()],
                default => ['kind' => 'sequence', 'id' => $resource->getSequenceTemplate()?->getId(), 'title' => $resource->getSequenceTemplate()?->getTitre()],
            };
        }

        /** @var list<LessonLogAttachment> $lessonLogDocuments */
        $lessonLogDocuments = $this->entityManager->createQueryBuilder()
            ->select('a', 'l', 's')
            ->from(LessonLogAttachment::class, 'a')
            ->join('a.lessonLog', 'l')
            ->join('l.lessonSession', 's')
            ->where('a.libraryNode IN (:files)')
            ->setParameter('files', $files)
            ->getQuery()
            ->getResult();

        foreach ($lessonLogDocuments as $document) {
            $node = $document->getLibraryNode();
            $session = $document->getLessonLog()?->getLessonSession();
            if (null === $node || null === $session) {
                continue;
            }

            $attachments[(int) $node->getId()][] = [
                'kind' => 'lesson_log',
                'id' => $session->getId(),
                'title' => \sprintf('%s - %s', $session->getDay()?->format('d/m/Y'), $session->getDisplayName()),
            ];
        }

        return $attachments;
    }
}
