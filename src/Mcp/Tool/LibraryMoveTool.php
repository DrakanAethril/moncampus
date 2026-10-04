<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\FileLibraryNode;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\FeatureAccess;
use App\Security\Voter\FileLibraryVoter;
use App\Service\FileLibraryNodeManager;
use App\Service\FileLibraryTree;
use App\Service\QuizFolderManager;
use App\Service\QuizFolderTree;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The drag of the quiz and file library screens - a row or a folder dropped onto a folder of the
 * rail - for several elements at once. A move, never a copy: the ids stay the same, so every link
 * to a quiz or a file keeps pointing at it.
 *
 * The call is refused whole before anything moves: every element is loaded through the voters and
 * every move checked first (a folder into itself or its own sub-folder, an element of somebody
 * else's library). The moves then run one by one with a flush between them, inside one
 * transaction: a folder's subtree and its siblings' names are read from the database, so the move
 * before has to be there.
 */
final readonly class LibraryMoveTool implements McpTool
{
    private const int MAX_ELEMENTS = 200;

    public function __construct(
        private McpLibraryAccess $library,
        private QuizFolderManager $quizFolders,
        private QuizFolderTree $quizTree,
        private FileLibraryNodeManager $fileNodes,
        private FileLibraryTree $fileTree,
        private FeatureAccess $featureAccess,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'library_move';
    }

    public function title(): string
    {
        return 'Déplacer des quiz, des fichiers ou des dossiers';
    }

    public function description(): string
    {
        return 'Déplace, sans les dupliquer, des éléments de la bibliothèque de quiz (`library: "quiz"`, quizIds et/ou folderIds) ou de fichiers (`library: "file"`, fileIds et/ou folderIds) vers un dossier de la même bibliothèque (targetFolderId), ou à sa racine (`targetFolderId: null`, à écrire explicitement). Un dossier déplacé emporte tout son contenu. Les identifiants ne changent pas : les liens vers ces quiz et fichiers restent valables. Si un nom est déjà pris dans le dossier d\'arrivée, il est complété d\'un numéro. Un dossier ne peut pas aller dans lui-même ni dans un de ses sous-dossiers ; au moindre élément refusé, rien n\'est déplacé.';
    }

    public function inputSchema(): array
    {
        $ids = ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_ELEMENTS];

        return [
            'type' => 'object',
            'properties' => [
                'library' => ['type' => 'string', 'enum' => ['quiz', 'file']],
                'quizIds' => $ids + ['description' => 'Quiz à déplacer (bibliothèque de quiz).'],
                'fileIds' => $ids + ['description' => 'Fichiers à déplacer (bibliothèque de fichiers).'],
                'folderIds' => $ids + ['description' => 'Dossiers à déplacer, avec tout leur contenu, de la même bibliothèque.'],
                'targetFolderId' => ['type' => ['integer', 'null'], 'description' => 'Dossier d\'arrivée, de la même bibliothèque ; null pour la racine.'],
            ],
            'required' => ['library', 'targetFolderId'],
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
        if (!\array_key_exists('targetFolderId', $call->arguments->toArray())) {
            throw new McpToolException('L\'argument « targetFolderId » est obligatoire : l\'identifiant du dossier d\'arrivée, ou null pour la racine.');
        }

        $folderIds = $this->ids($call, 'folderIds');
        $targetId = $call->optionalId('targetFolderId');

        return match ($call->arguments->string('library')) {
            'quiz' => $this->quiz($call, $this->ids($call, 'quizIds'), $folderIds, $targetId),
            'file' => $this->file($call, $this->ids($call, 'fileIds'), $folderIds, $targetId),
            default => throw new McpToolException('L\'argument « library » vaut « quiz » ou « file ».'),
        };
    }

    /**
     * @param list<int> $quizIds
     * @param list<int> $folderIds
     */
    private function quiz(McpToolCall $call, array $quizIds, array $folderIds, ?int $targetId): McpToolResult
    {
        $this->require(Feature::QuizLibrary, $call);
        $this->refuseOtherLibrary($call, 'fileIds', 'quiz');
        $this->refuseNothing($quizIds, $folderIds, 'quizIds');

        $target = $this->library->quizFolder($targetId);
        $ownerId = $target?->getOwner()->getId() ?? $call->user->getId();
        $quizzes = array_map(fn (int $id): QuizTemplate => $this->library->quiz($id), $quizIds);
        $folders = array_map(fn (int $id): QuizFolder => $this->library->quizFolder($id) ?? throw new \LogicException(), $folderIds);
        $refusals = [];

        foreach ($quizzes as $quiz) {
            if ($quiz->getTeacher()?->getId() !== $ownerId) {
                $refusals[] = \sprintf('le quiz « %s » n\'est pas dans la même bibliothèque que le dossier d\'arrivée', $quiz->getName());
            }
        }

        foreach ($folders as $folder) {
            if ($folder->getOwner()->getId() !== $ownerId) {
                $refusals[] = \sprintf('le dossier « %s » n\'est pas dans la même bibliothèque que le dossier d\'arrivée', $folder->getName());
            } elseif (null !== $target && ($target->getId() === $folder->getId() || $this->quizTree->isDescendantOf($target->getPath(), (int) $folder->getId()))) {
                $refusals[] = \sprintf('le dossier « %s » ne peut pas aller dans lui-même ni dans un de ses sous-dossiers', $folder->getName());
            }
        }

        $this->refuse($refusals);

        $moved = $this->entityManager->wrapInTransaction(function () use ($call, $quizzes, $folders, $target): array {
            $moved = [];

            foreach ($folders as $folder) {
                $this->quizFolders->moveFolder($folder, $target, $call->user) || throw new \LogicException('A checked folder move was refused.');
                $this->entityManager->flush();
                $moved[] = ['kind' => 'folder', 'id' => (int) $folder->getId(), 'name' => $folder->getName()];
            }

            foreach ($quizzes as $quiz) {
                $this->quizFolders->moveQuiz($quiz, $target, $call->user) || throw new \LogicException('A checked quiz move was refused.');
                $this->entityManager->flush();
                $moved[] = ['kind' => 'quiz', 'id' => (int) $quiz->getId(), 'name' => (string) $quiz->getName()];
            }

            return $moved;
        });

        $url = null === $target ? $this->links->url('app_library_quiz') : $this->links->quizFolder($target);

        return $this->answer($moved, $target?->getId(), $target?->getName(), $url);
    }

    /**
     * @param list<int> $fileIds
     * @param list<int> $folderIds
     */
    private function file(McpToolCall $call, array $fileIds, array $folderIds, ?int $targetId): McpToolResult
    {
        $this->require(Feature::FileLibrary, $call);
        $this->refuseOtherLibrary($call, 'quizIds', 'file');
        $this->refuseNothing($fileIds, $folderIds, 'fileIds');

        $target = $this->library->fileFolder($targetId);
        $ownerId = $target?->getOwner()->getId() ?? $call->user->getId();
        $nodes = [
            ...array_map(fn (int $id): FileLibraryNode => $this->library->fileFolder($id) ?? throw new \LogicException(), $folderIds),
            ...array_map(fn (int $id): FileLibraryNode => $this->library->file($id, FileLibraryVoter::EDIT), $fileIds),
        ];
        $refusals = [];

        foreach ($nodes as $node) {
            $label = $node->isFolder() ? 'le dossier' : 'le fichier';

            if ($node->getOwner()->getId() !== $ownerId) {
                $refusals[] = \sprintf('%s « %s » n\'est pas dans la même bibliothèque que le dossier d\'arrivée', $label, $node->getName());
            } elseif (null !== $target && $node->isFolder() && ($target->getId() === $node->getId() || $this->fileTree->isDescendantOf($target->getPath(), (int) $node->getId()))) {
                $refusals[] = \sprintf('le dossier « %s » ne peut pas aller dans lui-même ni dans un de ses sous-dossiers', $node->getName());
            }
        }

        $this->refuse($refusals);

        $moved = $this->entityManager->wrapInTransaction(function () use ($call, $nodes, $target): array {
            $moved = [];

            foreach ($nodes as $node) {
                $this->fileNodes->move($node, $target, $call->user) || throw new \LogicException('A checked file library move was refused.');
                $this->entityManager->flush();
                $moved[] = ['kind' => $node->isFolder() ? 'folder' : 'file', 'id' => (int) $node->getId(), 'name' => $node->getName()];
            }

            return $moved;
        });

        return $this->answer($moved, $target?->getId(), $target?->getName(), $this->links->fileFolder($target));
    }

    /**
     * @param list<array{kind: string, id: int, name: string}> $moved
     */
    private function answer(array $moved, ?int $targetId, ?string $targetName, string $url): McpToolResult
    {
        $where = null === $targetName ? 'à la racine de la bibliothèque' : \sprintf('dans le dossier « %s »', $targetName);

        return McpToolResult::data(
            \sprintf('%d élément(s) déplacé(s) %s : %s', \count($moved), $where, $url),
            ['moved' => $moved, 'target' => ['id' => $targetId, 'name' => $targetName, 'url' => $url]],
        );
    }

    /**
     * @return list<int>
     */
    private function ids(McpToolCall $call, string $key): array
    {
        $ids = array_values(array_unique(array_filter($call->arguments->ids($key), static fn (int $id): bool => $id > 0)));

        if (\count($ids) > self::MAX_ELEMENTS) {
            throw new McpToolException(\sprintf('Au plus %d éléments par appel dans « %s » : découpe le déplacement.', self::MAX_ELEMENTS, $key));
        }

        return $ids;
    }

    private function refuseOtherLibrary(McpToolCall $call, string $key, string $library): void
    {
        if ([] !== $call->arguments->ids($key)) {
            throw new McpToolException(\sprintf('« %s » ne s\'emploie pas avec `library: "%s"`.', $key, $library));
        }
    }

    /**
     * @param list<int> $itemIds
     * @param list<int> $folderIds
     */
    private function refuseNothing(array $itemIds, array $folderIds, string $itemKey): void
    {
        if ([] === $itemIds && [] === $folderIds) {
            throw new McpToolException(\sprintf('Rien à déplacer : indique « %s » et/ou « folderIds ».', $itemKey));
        }
    }

    /**
     * @param list<string> $refusals
     */
    private function refuse(array $refusals): void
    {
        if ([] !== $refusals) {
            throw new McpToolException('Rien n\'a été déplacé :', $refusals);
        }
    }

    private function require(Feature $feature, McpToolCall $call): void
    {
        if (!$this->featureAccess->isEnabled($feature, $call->user)) {
            throw new McpToolException('Cette bibliothèque n\'est pas ouverte pour votre compte.');
        }
    }
}
