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
use App\Service\CourseMaterialRenderer;
use App\Service\FileLibraryWriter;
use App\Service\FileLibraryWriteRefused;
use App\Service\GotenbergUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A course handout written by Claude, filed in the teacher's bibliothèque de fichiers: rendered on
 * the server from Markdown (App\Service\CourseMaterialRenderer) - a PDF by default, or the Markdown
 * itself, or a standalone HTML page - and written through the same gates as an upload
 * (App\Service\FileLibraryWriter). Generating the file here rather than asking the model for its
 * bytes is what keeps it cheap: a page of text is a page of text, not forty thousand characters of
 * base64 produced one token at a time.
 */
final readonly class FileCreateTool implements McpTool
{
    private const array FORMATS = ['pdf', 'md', 'html'];

    public function __construct(
        private McpLibraryAccess $library,
        private CourseMaterialRenderer $renderer,
        private FileLibraryWriter $writer,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'file_create';
    }

    public function title(): string
    {
        return 'Créer un support de cours';
    }

    public function description(): string
    {
        return 'Crée un fichier dans la bibliothèque de fichiers de l\'enseignant à partir d\'un contenu rédigé en Markdown (titres, listes, tableaux, blocs de code) : un PDF mis en page (`format: "pdf"`, par défaut), le Markdown brut (`"md"`, modifiable) ou une page HTML autonome (`"html"`). Le titre est imprimé en tête du document. Facultatif : le ranger dans un dossier (folderId). Pour le rattacher ensuite à une séquence ou une séance : file_link.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titre du document, qui sert aussi de nom de fichier.', 'maxLength' => 200],
                'content' => ['type' => 'string', 'description' => 'Le contenu, en Markdown (le titre n\'y est pas à répéter).'],
                'format' => ['type' => 'string', 'enum' => self::FORMATS, 'default' => 'pdf'],
                'folderId' => ['type' => 'integer', 'description' => 'Dossier de la bibliothèque de fichiers. Absent : la racine.'],
            ],
            'required' => ['title', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::FileLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $title = mb_substr($call->requiredString('title'), 0, 200);
        $content = $call->requiredString('content');
        $format = $call->arguments->string('format', 'pdf');
        if (!\in_array($format, self::FORMATS, true)) {
            throw new McpToolException('L\'argument « format » vaut « pdf », « md » ou « html ».');
        }
        $folder = $this->library->fileFolder($call->optionalId('folderId'));

        try {
            $bytes = match ($format) {
                'pdf' => $this->renderer->pdf($title, $content),
                'html' => $this->renderer->html($title, $content),
                default => '# '.$title."\n\n".$content."\n",
            };
        } catch (GotenbergUnavailableException) {
            throw new McpToolException('Le service de mise en page PDF ne répond pas. Réessayez, ou créez le support au format « md ».');
        }

        try {
            $file = $this->writer->write($call->user, $folder, $this->fileName($title, $format), $bytes);
        } catch (FileLibraryWriteRefused $refusal) {
            throw new McpToolException($refusal->getMessage());
        }
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf('Fichier « %s » créé dans la bibliothèque : %s', $file->getName(), $this->links->fileFolder($folder)),
            ['fileId' => $file->getId(), 'name' => $file->getName(), 'sizeBytes' => $file->getSizeBytes(), 'folderUrl' => $this->links->fileFolder($folder)],
            ['kind' => 'file', 'id' => (int) $file->getId()],
        );
    }

    private function fileName(string $title, string $format): string
    {
        // A title is free text; a file name is not. Separators and control characters go, the rest -
        // accents included - is what the teacher will recognise in their library.
        $name = (string) preg_replace('/\s*[\/\\\\:|]+\s*/u', ' - ', $title);
        $name = trim((string) preg_replace(['/[*?"<>\x00-\x1F]+/u', '/\s+/u'], ['', ' '], $name), " -\t\n");
        $name = '' === $name ? 'Support de cours' : $name;

        return str_ends_with(mb_strtolower($name), '.'.$format) ? $name : $name.'.'.$format;
    }
}
