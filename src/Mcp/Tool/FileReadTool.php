<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\FileLibraryNode;
use App\Entity\LibraryResource;
use App\Enum\Feature;
use App\Enum\LibraryResourceSourceType;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\LibraryResourceRepository;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\DocumentTextExtractor;
use App\Service\FileLibraryLinks;
use App\Service\FileUploadService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads a file for Claude - one of the library (`fileId`), or one deposited straight on a séquence,
 * a séance or a phase without going through the library (`resourceId`). The text comes out of
 * App\Service\DocumentTextExtractor, cut into pages of at most CHUNK characters so a long course
 * fits the client's limit on a tool result; a picture comes back as a picture.
 */
final readonly class FileReadTool implements McpTool
{
    /** Well under claude.ai's ~150 000 characters per result, leaving room for the envelope. */
    public const int CHUNK = 100_000;

    /** A file is read into memory to be parsed; past this it would cost the worker too much. */
    public const int MAX_BYTES = 30 * 1024 * 1024;

    public function __construct(
        private McpLibraryAccess $library,
        private LibraryResourceRepository $resources,
        private FileUploadService $uploads,
        private DocumentTextExtractor $extractor,
        private FileLibraryLinks $usages,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'file_read';
    }

    public function title(): string
    {
        return 'Lire un fichier';
    }

    public function description(): string
    {
        return 'Lit le contenu d\'un fichier de la bibliothèque (fileId, voir file_list) ou d\'une ressource déposée sur une séquence ou une séance (resourceId, voir sequence_get) : PDF, Word, PowerPoint, OpenDocument, Excel, HTML, texte ; une image est renvoyée comme image. Un texte long est découpé : relancer avec `offset` = `nextOffset` pour la suite. Indique aussi où le fichier est utilisé.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileId' => ['type' => 'integer'],
                'resourceId' => ['type' => 'integer'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Position (en caractères) à partir de laquelle lire la suite.'],
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
        $fileId = $call->optionalId('fileId');
        $resourceId = $call->optionalId('resourceId');

        if (null !== $fileId) {
            $node = $this->library->file($fileId);
            $usages = array_map(fn (array $usage): array => [
                'where' => $this->translator->trans($usage['where']),
                'what' => $usage['what'],
            ], $this->usages->usagesOf($node));

            return $this->read((string) $node->getStorageKey(), $node->getOriginalName() ?? $node->getName(), $node->getName(), $call, ['fileId' => $node->getId(), 'usedIn' => $usages]);
        }

        if (null !== $resourceId) {
            $resource = $this->resource($resourceId);
            if (LibraryResourceSourceType::Link === $resource->getType()) {
                return McpToolResult::data('Cette ressource est un lien, pas un fichier : son contenu n\'est pas dans MonCampus.', ['resourceId' => $resource->getId(), 'label' => $resource->getLabel(), 'link' => $resource->getUrl()]);
            }

            $node = $resource->getLibraryNode();
            $name = $node instanceof FileLibraryNode ? ($node->getOriginalName() ?? $node->getName()) : (string) $resource->getStorageKey();

            return $this->read((string) $resource->getStorageKey(), $name, (string) $resource->getLabel(), $call, ['resourceId' => $resource->getId(), 'fileId' => $node?->getId()]);
        }

        throw new McpToolException('Indiquez le fichier (fileId) ou la ressource (resourceId) à lire.');
    }

    /**
     * @param array<string, mixed> $identity
     */
    private function read(string $key, string $fileName, string $label, McpToolCall $call, array $identity): McpToolResult
    {
        if ('' === $key) {
            throw new McpToolException('Ce fichier n\'a pas de contenu enregistré.');
        }

        $size = $this->uploads->size($key);
        if ($size > self::MAX_BYTES) {
            return McpToolResult::data(\sprintf('« %s » est trop volumineux pour être lu ici (%d Mo).', $label, intdiv($size, 1024 * 1024)), $identity + ['name' => $label]);
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp-read-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary file.');
        }

        try {
            file_put_contents($path, $this->uploads->read($key));
            $document = $this->extractor->extract($path, $fileName);
        } finally {
            @unlink($path);
        }

        if (null !== $document->imageBytes && null !== $document->imageMimeType) {
            return new McpToolResult([
                ['type' => 'text', 'text' => \sprintf('Image « %s » :', $label)],
                ['type' => 'image', 'data' => base64_encode($document->imageBytes), 'mimeType' => $document->imageMimeType],
            ], $identity + ['name' => $label]);
        }

        if (null === $document->text) {
            return McpToolResult::data(\sprintf('« %s » : %s', $label, $document->note), $identity + ['name' => $label, 'readable' => false]);
        }

        $offset = max(0, $call->arguments->int('offset') ?? 0);
        $length = mb_strlen($document->text);
        $chunk = mb_substr($document->text, $offset, self::CHUNK);
        $next = $offset + self::CHUNK < $length ? $offset + self::CHUNK : null;

        return new McpToolResult(
            [['type' => 'text', 'text' => \sprintf(
                "« %s » (caractères %d à %d sur %d)%s\n\n%s",
                $label,
                $offset,
                min($length, $offset + self::CHUNK),
                $length,
                null === $next ? '' : \sprintf(' — suite avec offset=%d', $next),
                $chunk,
            )]],
            $identity + ['name' => $label, 'totalLength' => $length, 'offset' => $offset, 'nextOffset' => $next],
        );
    }

    /**
     * A resource is read under its séquence's voter: that is where « may I see this course » is
     * answered, whether the resource sits on the séquence, a séance or a phase.
     */
    private function resource(int $id): LibraryResource
    {
        $resource = $this->resources->find($id);
        $sequence = $resource?->getSequenceTemplate()
            ?? $resource?->getSeanceTemplate()?->getSequenceTemplate()
            ?? $resource?->getSeancePhaseTemplate()?->getSeanceTemplate()?->getSequenceTemplate();

        if (!$resource instanceof LibraryResource || null === $sequence) {
            throw new McpToolException(\sprintf('Ressource %d introuvable.', $id));
        }

        $this->library->sequence((int) $sequence->getId(), SequenceTemplateVoter::VIEW);

        return $resource;
    }
}
