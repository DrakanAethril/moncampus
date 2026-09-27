<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\QuizFolder;
use App\Entity\QuizQuestion;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceFolder;
use App\Enum\Feature;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\QuizFolderRepository;
use App\Repository\QuizTemplateRepository;
use App\Repository\SequenceFolderRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\FeatureAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The teacher's own quiz or séquence library: its folders and what is filed in them, with the ids
 * the other tools take. Only their own - a library is personal, and what colleagues share is read
 * on its own screen.
 *
 * Listed if either library is lit; the one asked for is checked on the call, like a screen of an
 * extinguished feature.
 */
final readonly class LibraryListTool implements McpTool
{
    public function __construct(
        private QuizTemplateRepository $quizzes,
        private QuizFolderRepository $quizFolders,
        private SequenceTemplateRepository $sequences,
        private SequenceFolderRepository $sequenceFolders,
        private EntityManagerInterface $entityManager,
        private FeatureAccess $featureAccess,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'library_list';
    }

    public function title(): string
    {
        return 'Lister ma bibliothèque';
    }

    public function description(): string
    {
        return 'Liste les dossiers et les éléments de la bibliothèque personnelle de l\'enseignant : `library: "quiz"` pour les quiz (nombre de questions), `library: "sequence"` pour les séquences pédagogiques (nombre de séances). Renvoie les identifiants à passer aux autres outils (quiz_get, sequence_get, quiz_link, folderId…).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'library' => ['type' => 'string', 'enum' => ['quiz', 'sequence'], 'description' => 'La bibliothèque à lister.'],
            ],
            'required' => ['library'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        return match ($call->arguments->string('library')) {
            'quiz' => $this->quizLibrary($call),
            'sequence' => $this->sequenceLibrary($call),
            default => throw new McpToolException('L\'argument « library » vaut « quiz » ou « sequence ».'),
        };
    }

    private function quizLibrary(McpToolCall $call): McpToolResult
    {
        $this->require(Feature::QuizLibrary, $call, 'la bibliothèque de quiz');

        $quizzes = $this->quizzes->findForTeacher($call->user);
        $counts = $this->counts(QuizQuestion::class, 'quizTemplate', array_map(static fn ($quiz): ?int => $quiz->getId(), $quizzes));

        $rows = [];
        foreach ($quizzes as $quiz) {
            $rows[] = [
                'id' => $quiz->getId(),
                'name' => $quiz->getName(),
                'subject' => $quiz->getSubject(),
                'folderId' => $quiz->getFolder()?->getId(),
                'questionCount' => $counts[(int) $quiz->getId()] ?? 0,
                'url' => $this->links->quiz($quiz),
            ];
        }

        return McpToolResult::data(
            \sprintf('%d quiz, %d dossiers.', \count($rows), \count($folders = $this->folders($this->quizFolders->findAllFor($call->user)))),
            ['folders' => $folders, 'quizzes' => $rows],
        );
    }

    private function sequenceLibrary(McpToolCall $call): McpToolResult
    {
        $this->require(Feature::SequenceLibrary, $call, 'la bibliothèque de séquences');

        $sequences = $this->sequences->findForTeacher($call->user);
        $counts = $this->counts(SeanceTemplate::class, 'sequenceTemplate', array_map(static fn ($sequence): ?int => $sequence->getId(), $sequences));

        $rows = [];
        foreach ($sequences as $sequence) {
            $rows[] = [
                'id' => $sequence->getId(),
                'title' => $sequence->getTitre(),
                'niveau' => $sequence->getNiveau()?->getLabel(),
                'option' => $sequence->getOption()?->getLabel(),
                'folderId' => $sequence->getFolder()?->getId(),
                'seanceCount' => $counts[(int) $sequence->getId()] ?? 0,
                'url' => $this->links->sequence($sequence),
            ];
        }

        return McpToolResult::data(
            \sprintf('%d séquences, %d dossiers.', \count($rows), \count($folders = $this->folders($this->sequenceFolders->findAllFor($call->user)))),
            ['folders' => $folders, 'sequences' => $rows],
        );
    }

    /**
     * @param list<QuizFolder>|list<SequenceFolder> $folders
     *
     * @return list<array{id: ?int, name: string, parentId: ?int}>
     */
    private function folders(array $folders): array
    {
        return array_map(static fn (QuizFolder|SequenceFolder $folder): array => [
            'id' => $folder->getId(),
            'name' => $folder->getName(),
            'parentId' => $folder->getParent()?->getId(),
        ], $folders);
    }

    /**
     * How many children each parent has, in one query rather than one per row.
     *
     * @param class-string    $class
     * @param list<int|null>  $ids
     *
     * @return array<int, int>
     */
    private function counts(string $class, string $association, array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (?int $id): bool => null !== $id));
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{parent: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(\sprintf('IDENTITY(c.%s) AS parent', $association), 'COUNT(c.id) AS total')
            ->from($class, 'c')
            ->where(\sprintf('c.%s IN (:ids)', $association))
            ->setParameter('ids', $ids)
            ->groupBy('parent')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['parent']] = (int) $row['total'];
        }

        return $counts;
    }

    private function require(Feature $feature, McpToolCall $call, string $what): void
    {
        if (!$this->featureAccess->isEnabled($feature, $call->user)) {
            throw new McpToolException(\sprintf('%s n\'est pas ouverte pour votre compte.', ucfirst($what)));
        }
    }
}
