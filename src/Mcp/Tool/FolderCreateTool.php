<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\FeatureAccess;
use App\Service\FileLibraryNodeManager;
use App\Service\QuizFolderManager;
use App\Service\SequenceFolderManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a folder in one of the three libraries that carry the same tree - quizzes, séquences,
 * files - through each one's own manager, which also makes the name unique among its siblings.
 */
final readonly class FolderCreateTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private QuizFolderManager $quizFolders,
        private SequenceFolderManager $sequenceFolders,
        private FileLibraryNodeManager $fileNodes,
        private FeatureAccess $featureAccess,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'folder_create';
    }

    public function title(): string
    {
        return 'Créer un dossier';
    }

    public function description(): string
    {
        return 'Crée un dossier dans la bibliothèque de quiz (`library: "quiz"`), de séquences (`"sequence"`) ou de fichiers (`"file"`), à la racine ou dans un dossier parent (parentId). Si le nom est déjà pris à cet endroit, il est complété d\'un numéro.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'library' => ['type' => 'string', 'enum' => ['quiz', 'sequence', 'file']],
                'name' => ['type' => 'string', 'maxLength' => 255],
                'parentId' => ['type' => 'integer', 'description' => 'Dossier parent, de la même bibliothèque. Absent : à la racine.'],
            ],
            'required' => ['library', 'name'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $name = mb_substr($call->requiredString('name'), 0, 255);
        $parentId = $call->optionalId('parentId');

        [$id, $finalName, $url, $kind] = match ($call->arguments->string('library')) {
            'quiz' => $this->quiz($call, $name, $parentId),
            'sequence' => $this->sequence($call, $name, $parentId),
            'file' => $this->file($call, $name, $parentId),
            default => throw new McpToolException('L\'argument « library » vaut « quiz », « sequence » ou « file ».'),
        };

        return McpToolResult::data(
            \sprintf('Dossier « %s » créé : %s', $finalName, $url),
            ['id' => $id, 'name' => $finalName, 'url' => $url],
            ['kind' => $kind, 'id' => $id],
        );
    }

    /** @return array{int, string, string, string} */
    private function quiz(McpToolCall $call, string $name, ?int $parentId): array
    {
        $this->require(Feature::QuizLibrary, $call);
        $folder = $this->quizFolders->createFolder($call->user, $this->library->quizFolder($parentId), $name);
        $this->entityManager->flush();

        return [(int) $folder->getId(), $folder->getName(), $this->links->quizFolder($folder), 'quiz_folder'];
    }

    /** @return array{int, string, string, string} */
    private function sequence(McpToolCall $call, string $name, ?int $parentId): array
    {
        $this->require(Feature::SequenceLibrary, $call);
        $folder = $this->sequenceFolders->createFolder($call->user, $this->library->sequenceFolder($parentId), $name);
        $this->entityManager->flush();

        return [(int) $folder->getId(), $folder->getName(), $this->links->sequenceFolder($folder), 'sequence_folder'];
    }

    /** @return array{int, string, string, string} */
    private function file(McpToolCall $call, string $name, ?int $parentId): array
    {
        $this->require(Feature::FileLibrary, $call);
        $folder = $this->fileNodes->createFolder($call->user, $this->library->fileFolder($parentId), $name);
        $this->entityManager->flush();

        return [(int) $folder->getId(), $folder->getName(), $this->links->fileFolder($folder), 'file_folder'];
    }

    private function require(Feature $feature, McpToolCall $call): void
    {
        if (!$this->featureAccess->isEnabled($feature, $call->user)) {
            throw new McpToolException('Cette bibliothèque n\'est pas ouverte pour votre compte.');
        }
    }
}
