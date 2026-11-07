<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\ContentShareScope;
use App\Enum\Feature;
use App\Mcp\McpContentShares;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Service\ContentShareComposer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The share modal's submit: one of the teacher's own quizzes, files or folders, séquences or séances
 * handed to colleagues by name, to a group, or to the whole establishment's catalogue.
 *
 * A share gives to read, never to own: it writes nothing in anybody's library, and a colleague's own
 * « Dupliquer chez moi » is what makes a copy theirs. The composer is the screen's: a scope carries
 * only the audience it names, and somebody who could never read a share (a student, a tutor, an
 * outside account) is dropped from a `users` pick - a share that names nobody is refused.
 */
final readonly class ShareCreateTool implements McpTool
{
    public function __construct(
        private McpContentShares $shares,
        private ContentShareComposer $composer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'share_create';
    }

    public function title(): string
    {
        return 'Partager un contenu à des collègues';
    }

    public function description(): string
    {
        return 'Partage à des collègues un contenu de la bibliothèque de l\'enseignant : un quiz (`type: "quiz"`), un fichier ou un dossier entier de la bibliothèque de fichiers (`"file"`), une séquence (`"sequence"`) ou une séance seule (`"seance"`), désigné par `id`. Le partage donne à lire, pas à posséder : le collègue le retrouve dans ses partages reçus et le duplique s\'il le veut. `scope: "users"` avec `userIds` (voir `share_recipients`), `"group"` avec `groupIds` (l\'équipe et toutes ses sous-équipes), ou `"catalog"` pour tout l\'établissement. `note` dit pourquoi on l\'envoie. Seul l\'auteur partage ; les élèves, tuteurs et comptes extérieurs ne lisent jamais un partage. Un nouveau partage s\'ajoute à ceux déjà en place.';
    }

    public function inputSchema(): array
    {
        $ids = ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 200];

        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => McpContentShares::TYPES],
                'id' => ['type' => 'integer', 'description' => 'Le quiz, le fichier ou dossier, la séquence ou la séance à partager.'],
                'scope' => ['type' => 'string', 'enum' => ['users', 'group', 'catalog']],
                'userIds' => $ids + ['description' => 'Avec `scope: "users"` : les collègues, trouvés avec `share_recipients`.'],
                'groupIds' => $ids + ['description' => 'Avec `scope: "group"` : les équipes, trouvées avec `share_recipients` (`groups: true`).'],
                'note' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Pourquoi on l\'envoie - facultatif, lu par les destinataires.'],
            ],
            'required' => ['type', 'id', 'scope'],
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
        $subject = $this->shares->ownedSubject($call->arguments->string('type'), $call->requiredId('id'), $call->user);
        $scope = ContentShareScope::tryFrom($call->arguments->string('scope'))
            ?? throw new McpToolException('L\'argument « scope » vaut « users », « group » ou « catalog ».');

        $userIds = $call->arguments->ids('userIds');
        $groupIds = $call->arguments->ids('groupIds');

        // The screen drops the list the chosen radio does not name; here a model that sent both
        // would believe both were used, so the mismatch is said instead.
        if (ContentShareScope::Users !== $scope && [] !== $userIds) {
            throw new McpToolException('« userIds » ne s\'emploie qu\'avec `scope: "users"`.');
        }

        if (ContentShareScope::Group !== $scope && [] !== $groupIds) {
            throw new McpToolException('« groupIds » ne s\'emploie qu\'avec `scope: "group"`.');
        }

        $note = trim($call->arguments->string('note'));
        $share = $this->composer->compose($subject, $call->user, $scope, $userIds, $groupIds, '' === $note ? null : mb_substr($note, 0, 2000));

        if (null === $share) {
            throw new McpToolException(ContentShareScope::Users === $scope ? 'Ce partage ne nomme personne qui puisse le lire : indique dans « userIds » des collègues enseignants ou personnels (voir `share_recipients`).' : 'Ce partage ne nomme aucune équipe existante : indique dans « groupIds » des équipes données par `share_recipients` (`groups: true`).');
        }

        $this->entityManager->flush();
        $row = $this->shares->row($share, $call->user);

        return McpToolResult::data(
            \sprintf('« %s » partagé. Partages en place sur ce contenu : %s', $share->getSubjectTitle(), $this->shares->subjectUrl($subject)),
            $row + ['subjectUrl' => $this->shares->subjectUrl($subject)],
            ['kind' => 'content_share', 'id' => (int) $share->getId()],
        );
    }
}
