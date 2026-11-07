<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\ContentShareScope;
use App\Enum\ContentShareSubject;
use App\Repository\ContentShareRepository;
use App\Repository\FileLibraryNodeRepository;
use App\Security\Voter\QuizTemplateVoter;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\ContentShareAccess;
use App\Service\ContentShareAudience;
use App\Service\ContentShareCatalog;

/**
 * What the sharing tools of the connector have in common - the doors of
 * App\Controller\ContentShareController, said once for the five tools that need them.
 *
 * The connector shares four of the five subjects: quizzes, files (a file or a whole folder),
 * séquences and séances. Progressions are left to the screen (the user's decision of 2026-11-07):
 * taking one back means picking a free matière, which the connector writes nowhere else. A
 * progression share is therefore neither listed nor named here.
 *
 * « Introuvable » is the one refusal for an id that does not exist and for one the teacher may not
 * reach, as in App\Mcp\McpLibraryAccess.
 */
final readonly class McpContentShares
{
    /** @var list<string> the `type` argument of the tools, in the order the descriptions name them */
    public const array TYPES = ['quiz', 'file', 'sequence', 'seance'];

    public function __construct(
        private ContentShareRepository $shares,
        private ContentShareAudience $audience,
        private ContentShareAccess $access,
        private ContentShareCatalog $catalog,
        private McpLibraryAccess $library,
        private FileLibraryNodeRepository $fileNodes,
        private McpLinks $links,
    ) {
    }

    /**
     * The item a teacher shares - **their own and nobody else's**, with no staff bypass: sharing is
     * an act of authorship (App\Service\ContentShareAccess::mayShare()). A file may be a file or a
     * folder; what is in the bin is not offered.
     */
    public function ownedSubject(string $type, int $id, User $user): SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode
    {
        [$subject, $ownerId] = match ($type) {
            'quiz' => [$quiz = $this->library->quiz($id, QuizTemplateVoter::EDIT), $quiz->getTeacher()?->getId()],
            'sequence' => [$sequence = $this->library->sequence($id, SequenceTemplateVoter::EDIT), $sequence->getTeacher()?->getId()],
            'seance' => [$seance = $this->library->seance($id, SequenceTemplateVoter::EDIT), $seance->getSequenceTemplate()?->getTeacher()?->getId()],
            'file' => [$node = $this->fileNode($id, $user), $node->getOwner()->getId()],
            default => throw new McpToolException('L\'argument « type » vaut « quiz », « file », « sequence » ou « seance ».'),
        };

        if (null === $ownerId || !$this->access->mayShare($ownerId, (int) $user->getId(), $user->getRoles())) {
            throw new McpToolException('Seul l\'auteur d\'un contenu le partage : celui-ci n\'est pas dans votre bibliothèque.');
        }

        return $subject;
    }

    /** A share this person may read - the one door onto « Dupliquer chez moi ». */
    public function readableShare(int $id, User $user): ContentShare
    {
        $share = $this->shares->find($id);

        return $share instanceof ContentShare && ContentShareSubject::Progression !== $share->getSubject() && $this->audience->allows($share, $user)
            ? $share
            : throw new McpToolException(\sprintf('Partage %d introuvable parmi ceux que vous pouvez lire.', $id));
    }

    /** One of the teacher's own shares - the one door onto « Retirer » and « Rétablir ». */
    public function ownedShare(int $id, User $user): ContentShare
    {
        $share = $this->shares->find($id);

        return $share instanceof ContentShare && ContentShareSubject::Progression !== $share->getSubject() && $share->getOwner()->getId() === $user->getId()
            ? $share
            : throw new McpToolException(\sprintf('Partage %d introuvable parmi vos partages.', $id));
    }

    /**
     * The rows of « Reçus », « Catalogue » or « Mes partages », progressions left out.
     *
     * @return list<ContentShare>
     */
    public function box(string $box, User $user): array
    {
        $userId = (int) $user->getId();

        $shares = match ($box) {
            // A withdrawn share stays in « Reçus » until its reader closes it, as on the screen.
            'received' => array_filter(
                $this->audience->filterReadable($this->shares->findReceivedCandidates($user), $user),
                static fn (ContentShare $share): bool => !$share->isRevoked() || !$share->isDismissedBy($userId),
            ),
            'catalog' => $this->audience->filterReadable($this->shares->findCatalogCandidates($user), $user),
            'mine' => $this->shares->findOwnedBy($user),
            default => throw new McpToolException('L\'argument « box » vaut « received », « catalog » ou « mine ».'),
        };

        return array_values(array_filter($shares, static fn (ContentShare $share): bool => ContentShareSubject::Progression !== $share->getSubject()));
    }

    /** The free-text search of the catalogue: title, author and tag labels, every term required. */
    public function matches(ContentShare $share, string $query): bool
    {
        return $this->catalog->matches($this->catalog->haystackOf($share), $query);
    }

    /**
     * One share as the model reads it. The audience is spelled out for the author only - a reader
     * learns who shared and why, never who else received it, like on the screen.
     *
     * @return array<string, mixed>
     */
    public function row(ContentShare $share, User $viewer): array
    {
        $owner = $share->getOwner();
        $mine = $owner->getId() === $viewer->getId();
        $node = $share->getLibraryNode();

        $row = [
            'shareId' => $share->getId(),
            'type' => $share->getSubject()->value,
            'subjectId' => $this->subjectId($share),
            'title' => $share->getSubjectTitle(),
            'isFolder' => null === $node ? null : $node->isFolder(),
            'tags' => $this->catalog->tagLabelsOf($share),
            'author' => $owner->getDisplayName() ?? $owner->getUsername(),
            'note' => $share->getNote(),
            'sharedOn' => $share->getCreationDate()->format('Y-m-d'),
            'scope' => $share->getScope()->value,
            'revoked' => $share->isRevoked(),
            'url' => $this->links->url('app_shares_open', ['id' => $share->getId()]),
        ];

        if ($mine) {
            $row['audience'] = $this->audienceOf($share);
            $row['duplicationCount'] = $share->getDuplicationCount();
        } else {
            $row['duplicatedByMeOn'] = $share->duplicatedAtBy((int) $viewer->getId())?->format('Y-m-d');
        }

        return $row;
    }

    /**
     * Who a share reaches, as the author's « Mes partages » says it - names, groups with the measured
     * count of who can read, or the whole establishment.
     *
     * @return array<string, mixed>
     */
    public function audienceOf(ContentShare $share): array
    {
        return match ($share->getScope()) {
            ContentShareScope::Users => ['users' => array_values(array_map(
                static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                $share->getUsers()->toArray(),
            ))],
            ContentShareScope::Group => [
                'groups' => array_values(array_map(static fn ($group): string => $group->getName(), $share->getGroups()->toArray())),
                'readerCount' => $this->audience->memberCount($share->getGroupIds()),
            ],
            ContentShareScope::Catalog => ['catalog' => 'tout l\'établissement (enseignants et personnels)'],
        };
    }

    /** Where the author finds the item itself - the screen the share modal opens from. */
    public function subjectUrl(SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode $subject): string
    {
        return match (true) {
            $subject instanceof SequenceTemplate => $this->links->sequence($subject),
            $subject instanceof SeanceTemplate => $this->links->seance($subject),
            $subject instanceof QuizTemplate => $this->links->url('app_library_quiz_settings', ['id' => $subject->getId()]),
            default => $this->links->fileFolder($subject->getParent()),
        };
    }

    private function subjectId(ContentShare $share): ?int
    {
        return $share->getQuizTemplate()?->getId()
            ?? $share->getSequenceTemplate()?->getId()
            ?? $share->getSeanceTemplate()?->getId()
            ?? $share->getLibraryNode()?->getId();
    }

    /** A file or a folder of the teacher's own library - the share screen's 404 for anybody else's. */
    private function fileNode(int $id, User $user): FileLibraryNode
    {
        $node = $this->fileNodes->find($id);

        return $node instanceof FileLibraryNode && !$node->isDeleted() && $node->getOwner()->getId() === $user->getId()
            ? $node
            : throw new McpToolException(\sprintf('Fichier ou dossier %d introuvable dans votre bibliothèque de fichiers.', $id));
    }
}
