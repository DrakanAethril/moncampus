<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\FileLibraryNode;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceFolder;
use App\Entity\SequenceTemplate;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\QuizFolderRepository;
use App\Repository\QuizTemplateRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceFolderRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Security\Voter\QuizFolderVoter;
use App\Security\Voter\QuizTemplateVoter;
use App\Security\Voter\SequenceFolderVoter;
use App\Security\Voter\SequenceTemplateVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Loads what a tool names by id, through the same voters the screens ask.
 *
 * « Introuvable » is the one answer for both an id that does not exist and one the teacher may not
 * touch - the 404-not-403 rule of the screens, for the same reason: saying « it exists, but not for
 * you » tells somebody else's library apart from an empty one.
 */
final readonly class McpLibraryAccess
{
    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private QuizTemplateRepository $quizzes,
        private QuizFolderRepository $quizFolders,
        private SequenceTemplateRepository $sequences,
        private SeanceTemplateRepository $seances,
        private SequenceFolderRepository $sequenceFolders,
        private FileLibraryNodeRepository $fileNodes,
    ) {
    }

    public function quiz(int $id, string $attribute = QuizTemplateVoter::EDIT): QuizTemplate
    {
        $quiz = $this->quizzes->find($id);

        return $quiz instanceof QuizTemplate && $this->authorization->isGranted($attribute, $quiz)
            ? $quiz
            : throw new McpToolException(\sprintf('Quiz %d introuvable dans votre bibliothèque.', $id));
    }

    public function quizFolder(?int $id): ?QuizFolder
    {
        if (null === $id) {
            return null;
        }

        $folder = $this->quizFolders->find($id);

        return $folder instanceof QuizFolder && $this->authorization->isGranted(QuizFolderVoter::EDIT, $folder)
            ? $folder
            : throw new McpToolException(\sprintf('Dossier de quiz %d introuvable dans votre bibliothèque.', $id));
    }

    public function sequence(int $id, string $attribute = SequenceTemplateVoter::EDIT): SequenceTemplate
    {
        $sequence = $this->sequences->find($id);

        return $sequence instanceof SequenceTemplate && $this->authorization->isGranted($attribute, $sequence)
            ? $sequence
            : throw new McpToolException(\sprintf('Séquence %d introuvable dans votre bibliothèque.', $id));
    }

    /**
     * A séance is reached through its séquence: that is where the voter's rule is written.
     */
    public function seance(int $id, string $attribute = SequenceTemplateVoter::EDIT): SeanceTemplate
    {
        $seance = $this->seances->find($id);
        $sequence = $seance?->getSequenceTemplate();

        return $seance instanceof SeanceTemplate && null !== $sequence && $this->authorization->isGranted($attribute, $sequence)
            ? $seance
            : throw new McpToolException(\sprintf('Séance %d introuvable dans votre bibliothèque.', $id));
    }

    public function sequenceFolder(?int $id): ?SequenceFolder
    {
        if (null === $id) {
            return null;
        }

        $folder = $this->sequenceFolders->find($id);

        return $folder instanceof SequenceFolder && $this->authorization->isGranted(SequenceFolderVoter::EDIT, $folder)
            ? $folder
            : throw new McpToolException(\sprintf('Dossier de séquences %d introuvable dans votre bibliothèque.', $id));
    }

    /**
     * A folder of the file library, or null for its root. A file in the bin is not found: the bin
     * is where a teacher put it on purpose.
     */
    public function fileFolder(?int $id, string $attribute = FileLibraryVoter::EDIT): ?FileLibraryNode
    {
        if (null === $id) {
            return null;
        }

        $node = $this->fileNodes->find($id);

        return $node instanceof FileLibraryNode && $node->isFolder() && !$node->isDeleted() && $this->authorization->isGranted($attribute, $node)
            ? $node
            : throw new McpToolException(\sprintf('Dossier %d introuvable dans votre bibliothèque de fichiers.', $id));
    }

    public function file(int $id, string $attribute = FileLibraryVoter::VIEW): FileLibraryNode
    {
        $node = $this->fileNodes->find($id);

        return $node instanceof FileLibraryNode && $node->isFile() && !$node->isDeleted() && $this->authorization->isGranted($attribute, $node)
            ? $node
            : throw new McpToolException(\sprintf('Fichier %d introuvable dans votre bibliothèque de fichiers.', $id));
    }
}
