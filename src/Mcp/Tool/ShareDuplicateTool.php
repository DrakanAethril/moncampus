<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\QuizFolder;
use App\Entity\SequenceFolder;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\ContentShareSubject;
use App\Enum\Feature;
use App\Mcp\McpContentShares;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\FeatureAccess;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\ByteSize;
use App\Service\ContentShareQuotaException;
use App\Service\FileLibraryNodeDuplicator;
use App\Service\FileLibraryQuota;
use App\Service\QuizFolderManager;
use App\Service\QuizTemplateDuplicator;
use App\Service\SequenceDuplicator;
use App\Service\SequenceFolderManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Dupliquer chez moi » - a share a colleague made, copied into the teacher's own library, through
 * the screen's own duplicators. The copy is theirs and keeps no link to its source: the author
 * withdrawing the share later takes nothing back.
 *
 * `dryRun` is the confirmation screen: what will be created, what it weighs and whether it fits the
 * quota. The write is all-or-nothing wherever files are involved - the quota is asked once with the
 * sum, and a refusal writes nothing at all.
 *
 * Where the copy lands is the teacher's to say, and only ever in their own library - every folder
 * and séquence named is checked to be theirs, the staff bypass of the folder voters included: a
 * copy filed in a colleague's library would be the opposite of what this gesture is.
 */
final readonly class ShareDuplicateTool implements McpTool
{
    public function __construct(
        private McpContentShares $shares,
        private McpLibraryAccess $library,
        private QuizTemplateDuplicator $quizzes,
        private SequenceDuplicator $sequences,
        private FileLibraryNodeDuplicator $files,
        private QuizFolderManager $quizFolders,
        private SequenceFolderManager $sequenceFolders,
        private FileLibraryQuota $quota,
        private FeatureAccess $featureAccess,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'share_duplicate';
    }

    public function title(): string
    {
        return 'Dupliquer un contenu partagé';
    }

    public function description(): string
    {
        return 'Duplique dans la bibliothèque de l\'enseignant un contenu qu\'un collègue lui a partagé (`shareId`, voir `share_list`). La copie lui appartient et ne dépend plus de l\'original. `dryRun: true` dit d\'abord ce qui sera créé, son poids et s\'il tient dans le quota de la bibliothèque de fichiers. `folderId` range la copie : un dossier de quiz pour un quiz, de séquences pour une séquence, de fichiers pour un fichier ou un dossier. Une séance va dans une séquence de l\'enseignant (`sequenceId`) ou, sans lui, dans une nouvelle séquence portant son titre. Les supports d\'une séquence ou d\'une séance sont copiés dans la bibliothèque de fichiers, sous `resourcesFolderId` (racine par défaut). Les quiz rattachés à une séquence ne sont pas copiés avec elle : ils se partagent à part. Si le quota ne suffit pas, rien n\'est copié.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shareId' => ['type' => 'integer'],
                'dryRun' => ['type' => 'boolean', 'description' => 'true pour seulement décrire la copie, sans rien écrire.'],
                'folderId' => ['type' => 'integer', 'description' => 'Dossier d\'arrivée de la copie, dans la bibliothèque de son type. Absent : à la racine.'],
                'sequenceId' => ['type' => 'integer', 'description' => 'Séance seulement : la séquence de l\'enseignant qui la reçoit, en dernière position.'],
                'resourcesFolderId' => ['type' => 'integer', 'description' => 'Séquence ou séance : dossier de la bibliothèque de fichiers où copier ses supports.'],
            ],
            'required' => ['shareId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::ContentSharing];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $share = $this->shares->readableShare($call->requiredId('shareId'), $call->user);
        $subject = $share->getSubject();

        $this->refuseArgument($call, 'sequenceId', ContentShareSubject::Seance !== $subject, 'ne s\'emploie que pour une séance');
        $this->refuseArgument($call, 'resourcesFolderId', !\in_array($subject, [ContentShareSubject::Sequence, ContentShareSubject::Seance], true), 'ne s\'emploie que pour une séquence ou une séance');
        $this->refuseArgument($call, 'folderId', ContentShareSubject::Seance === $subject, 'ne s\'emploie pas pour une séance : elle va dans une séquence (« sequenceId »), et une nouvelle séquence se range ensuite avec `library_move`');

        $dryRun = $call->arguments->bool('dryRun');

        try {
            $result = match ($subject) {
                ContentShareSubject::Quiz => $this->quiz($call, $share, $dryRun),
                ContentShareSubject::Sequence => $this->sequence($call, $share, $dryRun),
                ContentShareSubject::Seance => $this->seance($call, $share, $dryRun),
                ContentShareSubject::File => $this->file($call, $share, $dryRun),
                ContentShareSubject::Progression => throw new \LogicException('Progression shares are not offered by the connector.'),
            };
        } catch (ContentShareQuotaException $refusal) {
            throw new McpToolException(\sprintf('Rien n\'a été copié : il manque %s dans la bibliothèque de fichiers (la copie pèse %s, il reste %s). L\'enseignant peut faire de la place dans MonCampus, puis réessayer.', ByteSize::format($refusal->shortfallBytes()), ByteSize::format($refusal->requiredBytes), ByteSize::format($refusal->remainingBytes)));
        }

        if (!$dryRun) {
            $share->markDuplicatedBy((int) $call->user->getId());
            $this->entityManager->flush();
        }

        return $result;
    }

    private function quiz(McpToolCall $call, ContentShare $share, bool $dryRun): McpToolResult
    {
        $source = $share->getQuizTemplate() ?? throw new \LogicException();
        $folder = $this->quizFolder($call);

        if ($dryRun) {
            return McpToolResult::data(
                \sprintf('Le quiz « %s » sera copié avec ses %d questions ; ses illustrations ne pèsent rien dans la bibliothèque de fichiers.', $source->getName(), $source->getQuestions()->count()),
                ['dryRun' => true, 'type' => 'quiz', 'title' => $source->getName(), 'questionCount' => $source->getQuestions()->count(), 'folderId' => $folder?->getId()],
            );
        }

        // The duplicator does not flush - the caller owns its unit of work - so filing the copy
        // before the flush makes it one write.
        $copy = $this->quizzes->duplicate($source, $call->user, $call->user);
        $this->quizFolders->moveQuiz($copy, $folder, $call->user) || throw new \LogicException('A checked quiz folder was refused.');
        $this->entityManager->flush();

        return $this->created('quiz', (int) $copy->getId(), (string) $copy->getName(), $this->links->quiz($copy));
    }

    private function sequence(McpToolCall $call, ContentShare $share, bool $dryRun): McpToolResult
    {
        $source = $share->getSequenceTemplate() ?? throw new \LogicException();
        $folder = $this->sequenceFolder($call);
        $resourcesFolder = $this->fileFolder($call, 'resourcesFolderId');

        if ($dryRun) {
            $plan = $this->sequences->plan($source);

            return $this->plan($call, 'sequence', (string) $source->getTitre(), $plan['folders'], $plan['fileCount'], $plan['linkCount'], $plan['totalBytes'], [
                'seanceCount' => $source->getSeanceTemplates()->count(),
                'folderId' => $folder?->getId(),
                'resourcesFolderId' => $resourcesFolder?->getId(),
            ]);
        }

        // The duplicator writes in its own transaction and flushes; the copy is filed afterwards, in
        // a folder already checked to be the teacher's.
        $copy = $this->sequences->duplicate($source, $call->user, $resourcesFolder);

        if (null !== $folder) {
            $this->sequenceFolders->moveSequence($copy, $folder, $call->user) || throw new \LogicException('A checked séquence folder was refused.');
            $this->entityManager->flush();
        }

        return $this->created('sequence', (int) $copy->getId(), (string) $copy->getTitre(), $this->links->sequence($copy));
    }

    private function seance(McpToolCall $call, ContentShare $share, bool $dryRun): McpToolResult
    {
        $source = $share->getSeanceTemplate() ?? throw new \LogicException();
        $target = $this->targetSequence($call);
        $resourcesFolder = $this->fileFolder($call, 'resourcesFolderId');

        if ($dryRun) {
            $plan = $this->sequences->planSeance($source);

            return $this->plan($call, 'seance', (string) $source->getTitre(), $plan['folders'], $plan['fileCount'], $plan['linkCount'], $plan['totalBytes'], [
                'phaseCount' => $source->getSeancePhaseTemplates()->count(),
                'into' => null === $target
                    ? 'une nouvelle séquence portant le titre de la séance'
                    : \sprintf('la séquence « %s », en dernière position', $target->getTitre()),
                'resourcesFolderId' => $resourcesFolder?->getId(),
            ]);
        }

        $copy = $this->sequences->duplicateSeance($source, $call->user, $target, $resourcesFolder);
        $sequence = $copy->getSequenceTemplate();

        return $this->created('seance', (int) $copy->getId(), (string) $copy->getTitre(), $this->links->seance($copy), [
            'sequenceId' => $sequence?->getId(),
            'sequenceTitle' => $sequence?->getTitre(),
            'newSequence' => null === $target,
        ]);
    }

    private function file(McpToolCall $call, ContentShare $share, bool $dryRun): McpToolResult
    {
        $source = $share->getLibraryNode() ?? throw new \LogicException();

        // What its author put in the bin is not what they shared.
        if ($source->isDeleted()) {
            throw new McpToolException(\sprintf('« %s » a été mis à la corbeille par son auteur : il n\'y a plus rien à copier.', $source->getName()));
        }

        $folder = $this->fileFolder($call, 'folderId');

        if ($dryRun) {
            $plan = $this->files->plan($source);

            return McpToolResult::data(
                \sprintf('%s « %s » sera copié : %d fichier(s), %d dossier(s), %s.', $source->isFolder() ? 'Le dossier' : 'Le fichier', $source->getName(), $plan['fileCount'], $plan['folderCount'], ByteSize::format($plan['totalBytes'])),
                ['dryRun' => true, 'type' => 'file', 'title' => $source->getName(), 'isFolder' => $source->isFolder(), 'folderId' => $folder?->getId()] + $plan + $this->quotaAfter($call->user, $plan['totalBytes']),
            );
        }

        $copy = $this->files->duplicate($source, $call->user, $folder);

        return $this->created($copy->isFolder() ? 'file_folder' : 'file', (int) $copy->getId(), $copy->getName(), $this->links->fileFolder($copy->isFolder() ? $copy : $copy->getParent()));
    }

    /**
     * @param list<array{name: string, parentIndex: int|null, files: list<array{label: string, storageKey: string|null, bytes: int}>}> $folders
     * @param array<string, mixed>                                                                                                     $extra
     */
    private function plan(McpToolCall $call, string $type, string $title, array $folders, int $fileCount, int $linkCount, int $totalBytes, array $extra): McpToolResult
    {
        return McpToolResult::data(
            \sprintf('« %s » sera copié : %d support(s) dans %d dossier(s) de la bibliothèque de fichiers, %d lien(s), %s.', $title, $fileCount, \count($folders), $linkCount, ByteSize::format($totalBytes)),
            ['dryRun' => true, 'type' => $type, 'title' => $title]
                + $extra
                + [
                    'filesFolders' => array_map(static fn (array $folder): array => ['name' => $folder['name'], 'fileCount' => \count($folder['files'])], $folders),
                    'fileCount' => $fileCount,
                    'linkCount' => $linkCount,
                    'totalBytes' => $totalBytes,
                ]
                + $this->quotaAfter($call->user, $totalBytes),
        );
    }

    /** @return array{fits: bool, remainingBytes: int, remaining: string} */
    private function quotaAfter(User $user, int $bytes): array
    {
        $remaining = $this->quota->remainingBytes($user);

        return ['fits' => $this->quota->accepts($user, $bytes), 'remainingBytes' => $remaining, 'remaining' => ByteSize::format($remaining)];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function created(string $kind, int $id, string $name, string $url, array $extra = []): McpToolResult
    {
        return McpToolResult::data(
            \sprintf('« %s » dupliqué dans votre bibliothèque : %s', $name, $url),
            ['id' => $id, 'type' => $kind, 'name' => $name, 'url' => $url] + $extra,
            ['kind' => $kind, 'id' => $id],
        );
    }

    private function quizFolder(McpToolCall $call): ?QuizFolder
    {
        $id = $call->optionalId('folderId');

        if (null === $id) {
            return null;
        }

        $this->require(Feature::QuizLibrary, $call);
        $folder = $this->library->quizFolder($id);

        return null !== $folder && $folder->getOwner()->getId() === $call->user->getId()
            ? $folder
            : throw new McpToolException(\sprintf('Dossier de quiz %d introuvable dans votre bibliothèque.', $id));
    }

    private function sequenceFolder(McpToolCall $call): ?SequenceFolder
    {
        $id = $call->optionalId('folderId');

        if (null === $id) {
            return null;
        }

        $this->require(Feature::SequenceLibrary, $call);
        $folder = $this->library->sequenceFolder($id);

        return null !== $folder && $folder->getOwner()->getId() === $call->user->getId()
            ? $folder
            : throw new McpToolException(\sprintf('Dossier de séquences %d introuvable dans votre bibliothèque.', $id));
    }

    private function fileFolder(McpToolCall $call, string $key): ?FileLibraryNode
    {
        $id = $call->optionalId($key);

        if (null === $id) {
            return null;
        }

        $this->require(Feature::FileLibrary, $call);
        $folder = $this->library->fileFolder($id);

        return null !== $folder && $folder->getOwner()->getId() === $call->user->getId()
            ? $folder
            : throw new McpToolException(\sprintf('Dossier %d introuvable dans votre bibliothèque de fichiers.', $id));
    }

    /** The séquence a séance joins - the teacher's own, never a colleague's (the screen's rule). */
    private function targetSequence(McpToolCall $call): ?SequenceTemplate
    {
        $id = $call->optionalId('sequenceId');

        if (null === $id) {
            return null;
        }

        $sequence = $this->library->sequence($id, SequenceTemplateVoter::EDIT);

        return $sequence->getTeacher()?->getId() === $call->user->getId()
            ? $sequence
            : throw new McpToolException(\sprintf('Séquence %d introuvable dans votre bibliothèque.', $id));
    }

    private function refuseArgument(McpToolCall $call, string $key, bool $refused, string $why): void
    {
        if ($refused && null !== $call->optionalId($key)) {
            throw new McpToolException(\sprintf('« %s » %s.', $key, $why));
        }
    }

    private function require(Feature $feature, McpToolCall $call): void
    {
        if (!$this->featureAccess->isEnabled($feature, $call->user)) {
            throw new McpToolException('Cette bibliothèque n\'est pas ouverte pour votre compte.');
        }
    }
}
