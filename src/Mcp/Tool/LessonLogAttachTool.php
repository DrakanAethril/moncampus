<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\FileLibraryNode;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Enum\Feature;
use App\Enum\LessonLogAttachmentSourceType;
use App\Enum\LessonLogSection;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpTimetable;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\LessonLogRepository;
use App\Security\FeatureAccess;
use App\Security\Voter\FileLibraryVoter;
use App\Security\Voter\LessonLogVoter;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Files a document under one part of a séance's cahier de texte - the screen's « + Ajouter » of
 * that part, with a library file picked or an external link typed. Same door as
 * App\Controller\LessonLogController::addAttachment(): LessonLogVoter::EDIT, the teacher who
 * delivers the créneau or their co-animator.
 *
 * A library file is **a reference, not a copy** (App\Service\UploadIntake::store()): the row takes
 * the node's own storage key and names the node, so file_list sees where it is used and correcting
 * the file in the library corrects it here. A document never carries a visibility of its own from
 * here: it follows its part, which is how the screen files one too - a part still hidden hides it.
 *
 * Filing the same file (or the same link) twice under the same part is answered with the row
 * already there, not a second one: a model that retries a call must not leave the students two
 * identical lines.
 */
final readonly class LessonLogAttachTool implements McpTool
{
    private const string UPLOAD_PREFIX = 'lesson-logs/';
    private const int MAX_URL_LENGTH = 2048;

    public function __construct(
        private McpTimetable $timetable,
        private McpLibraryAccess $library,
        private LessonLogRepository $logs,
        private UploadIntake $intake,
        private FeatureAccess $featureAccess,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'lesson_log_attach';
    }

    public function title(): string
    {
        return 'Joindre un document au cahier de texte';
    }

    public function description(): string
    {
        return 'Joint un document à une partie du cahier de texte d\'une séance que l\'enseignant assure (sessionId, voir timetable_get) : soit un fichier de sa bibliothèque (fileId, voir file_list ; pour un support que tu rédiges, crée-le d\'abord avec file_create ou file_upload), soit un lien externe (url, http ou https). « section » choisit la partie : before (travail à faire avant), during (contenu de la séance, par défaut), after (travail à faire après). Le fichier n\'est pas copié : c\'est un lien vers la bibliothèque. `label` est le nom affiché aux étudiants (le nom du fichier par défaut). Le document suit la visibilité de sa partie : si elle est masquée, les étudiants ne le voient pas. Rien n\'est retiré ni remplacé ; joindre deux fois le même document à la même partie ne crée pas de doublon.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sessionId' => ['type' => 'integer', 'description' => 'La séance (voir timetable_get).'],
                'fileId' => ['type' => 'integer', 'description' => 'Un fichier de la bibliothèque (voir file_list). Exclusif de url.'],
                'url' => ['type' => 'string', 'maxLength' => self::MAX_URL_LENGTH, 'description' => 'Un lien externe, http ou https. Exclusif de fileId.'],
                'section' => ['type' => 'string', 'enum' => array_map(static fn (LessonLogSection $s): string => $s->value, LessonLogSection::cases()), 'default' => LessonLogSection::During->value],
                'label' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Nom affiché aux étudiants.'],
            ],
            'required' => ['sessionId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        // A link needs nothing more; a library file is checked on the call (see file()).
        return [Feature::LessonLog];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $session = $this->timetable->session($call->requiredId('sessionId'), LessonLogVoter::EDIT);

        $sectionValue = $call->arguments->string('section');
        $section = '' === $sectionValue ? LessonLogSection::During : LessonLogSection::tryFrom($sectionValue)
            ?? throw new McpToolException('« section » vaut before, during ou after.');

        $fileId = $call->optionalId('fileId');
        $url = trim($call->arguments->string('url'));
        if ((null === $fileId) === ('' === $url)) {
            throw new McpToolException('Indiquez soit un fichier de la bibliothèque (fileId), soit un lien (url) - un seul des deux.');
        }

        // Everything is resolved before the cahier de texte is opened: a refused file or link
        // leaves no empty cahier de texte behind.
        $file = null === $fileId ? null : $this->file($call, $fileId);
        if (null === $file) {
            $this->assertLink($url);
        }

        $log = $this->logs->findOneBySession($session);
        $isNew = null === $log;
        $log ??= new LessonLog($session);

        $label = mb_substr(trim($call->arguments->string('label')), 0, 255);
        $existing = $isNew ? null : $this->alreadyFiled($log, $section, $file, $url);

        if (null === $existing) {
            $attachment = new LessonLogAttachment($log, '' !== $label ? $label : ($file?->getName() ?? mb_substr($url, 0, 255)));
            $attachment->setSection($section);

            if (null !== $file) {
                $attachment->setType(LessonLogAttachmentSourceType::Library);
                $attachment->setStorageKey($this->intake->store($file, self::UPLOAD_PREFIX, (string) $file->getName()));
                $attachment->setLibraryNode($file);
            } else {
                $attachment->setType(LessonLogAttachmentSourceType::Link);
                $attachment->setUrl($url);
            }

            if ($isNew) {
                $log->setCreatedBy($call->user);
            } else {
                $log->setLastUpdatedBy($call->user);
                $log->setLastUpdatedDate($this->clock->now());
            }
            $this->entityManager->persist($log);
            $this->entityManager->persist($attachment);
            $this->entityManager->flush();
        } else {
            $attachment = $existing;
        }

        $logUrl = $this->timetable->logUrl($session);
        $part = McpTimetable::sectionLabel($section);

        return McpToolResult::data(
            \sprintf(
                '%s « %s » %s dans la partie « %s » du cahier de texte du %s (%s). Visibilité pour les étudiants : %s. À vérifier ici : %s',
                null === $file ? 'Lien' : 'Document',
                $attachment->getLabel(),
                null === $existing ? 'joint' : 'était déjà joint',
                $part,
                $session->getDay()?->format('d/m/Y'),
                $session->getDisplayName(),
                null === $attachment->getVisibleAt()
                    ? McpTimetable::visibilityLabel($log, $section).', celle de sa partie'
                    : 'propre au document, à partir du '.$attachment->getVisibleAt()->format('d/m/Y H:i'),
                $logUrl,
            ),
            [
                'sessionId' => $session->getId(),
                'attachmentId' => $attachment->getId(),
                'section' => $section->value,
                'created' => null === $existing,
                'url' => $logUrl,
                'parts' => $this->timetable->logParts($log),
            ],
            ['kind' => 'lesson_log', 'id' => (int) $log->getId()],
        );
    }

    private function file(McpToolCall $call, int $fileId): FileLibraryNode
    {
        if (!$this->featureAccess->isEnabled(Feature::FileLibrary, $call->user)) {
            throw new McpToolException('La bibliothèque de fichiers n\'est pas activée pour vous : joignez plutôt un lien (url).');
        }

        return $this->library->file($fileId, FileLibraryVoter::LINK);
    }

    /**
     * A link a student will click: absolute, http or https - never a `javascript:` or a local
     * path the model made up.
     */
    private function assertLink(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        if (mb_strlen($url) > self::MAX_URL_LENGTH || false === filter_var($url, \FILTER_VALIDATE_URL) || !\in_array($scheme, ['http', 'https'], true)) {
            throw new McpToolException('Le lien doit être une adresse complète en http ou https.');
        }
    }

    private function alreadyFiled(LessonLog $log, LessonLogSection $section, ?FileLibraryNode $file, string $url): ?LessonLogAttachment
    {
        foreach ($log->getAttachmentsForSection($section) as $attachment) {
            $same = null !== $file
                ? $attachment->getLibraryNode()?->getId() === $file->getId()
                : LessonLogAttachmentSourceType::Link === $attachment->getType() && $attachment->getUrl() === $url;

            if ($same) {
                return $attachment;
            }
        }

        return null;
    }
}
