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
use App\Service\FileLibraryWriter;
use App\Service\FileLibraryWriteRefused;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A small file Claude already holds - a spreadsheet, a diagram, a script it produced in its own
 * sandbox - deposited as bytes. Capped low on purpose: the model writes base64 one token at a time,
 * so anything past a few hundred kilobytes is slow and costly for the teacher, and file_create is
 * the better road for anything that is text.
 */
final readonly class FileUploadTool implements McpTool
{
    public const int MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private McpLibraryAccess $library,
        private FileLibraryWriter $writer,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'file_upload';
    }

    public function title(): string
    {
        return 'Déposer un petit fichier';
    }

    public function description(): string
    {
        return 'Dépose dans la bibliothèque de fichiers un petit fichier fourni en base64 (2 Mo au plus) : tableur, image, script, archive… Le nom doit porter l\'extension. Mêmes contrôles qu\'un dépôt dans MonCampus (types acceptés, quota, antivirus). Pour un support rédigé, préférer file_create.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Nom du fichier, extension comprise (ex. « adressage.xlsx »).'],
                'base64' => ['type' => 'string', 'description' => 'Le contenu du fichier, encodé en base64.'],
                'folderId' => ['type' => 'integer'],
            ],
            'required' => ['name', 'base64'],
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
        $name = mb_substr($call->requiredString('name'), 0, 255);
        $bytes = base64_decode(preg_replace('/\s+/', '', $call->requiredString('base64')) ?? '', true);
        if (false === $bytes || '' === $bytes) {
            throw new McpToolException('Le contenu n\'est pas du base64 valide.');
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new McpToolException('Ce fichier dépasse 2 Mo : déposez-le directement dans MonCampus.');
        }
        $folder = $this->library->fileFolder($call->optionalId('folderId'));

        try {
            $file = $this->writer->write($call->user, $folder, $name, $bytes);
        } catch (FileLibraryWriteRefused $refusal) {
            throw new McpToolException($refusal->getMessage());
        }
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf('Fichier « %s » déposé : %s', $file->getName(), $this->links->fileFolder($folder)),
            ['fileId' => $file->getId(), 'name' => $file->getName(), 'sizeBytes' => $file->getSizeBytes()],
            ['kind' => 'file', 'id' => (int) $file->getId()],
        );
    }
}
