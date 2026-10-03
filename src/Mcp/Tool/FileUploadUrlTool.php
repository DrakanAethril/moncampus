<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\McpUploadSlot;
use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\OAuth\OAuthSecret;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryUploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An address to send one file to, for the files Claude holds in its own sandbox and that are too
 * large to write as base64 (file_upload): a polycopié, a .zip of slides with their fonts, a video.
 * Claude then runs `curl -X PUT --data-binary @file "<uploadUrl>"`, and App\Controller\McpUploadController
 * puts the bytes into the library **through the same gates as an upload** - App\Service\FileLibraryWriter.
 *
 * The platform never fetches anything itself: the bytes come to it, from whoever holds the address.
 * Hence everything is bound to the address beforehand (App\Entity\McpUploadSlot) - teacher,
 * connection, folder, name, exact size, optional SHA-256 - and what can be refused on those alone is
 * refused here, before Claude sends a byte: the type of the name, the ceiling for that type, the
 * quota.
 */
final readonly class FileUploadUrlTool implements McpTool
{
    public const int LIFETIME_MINUTES = 15;

    public const string SECRET_PREFIX = 'mcup';

    public function __construct(
        private McpLibraryAccess $library,
        private FileLibraryUploadValidator $uploadPolicy,
        private FileLibraryQuota $quota,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'file_upload_url';
    }

    public function title(): string
    {
        return 'Obtenir une adresse d\'envoi de fichier';
    }

    public function description(): string
    {
        return 'Pour déposer dans la bibliothèque de fichiers un fichier que tu détiens (PDF, archive .zip, vidéo, image…) sans l\'écrire en base64. Donne son nom avec l\'extension, sa taille exacte en octets (`size`), le dossier (`folderId`, facultatif) et, si possible, son empreinte SHA-256. Renvoie `uploadUrl`, valable '.self::LIFETIME_MINUTES.' minutes et pour un seul envoi : envoie le fichier avec `curl -X PUT --data-binary @fichier "<uploadUrl>"`. La réponse donne le `fileId`, à passer ensuite à course_material_add, lesson_log_attach, etc. Mêmes contrôles qu\'un dépôt dans MonCampus (types acceptés, plafond par type, quota, antivirus) ; un envoi refusé consomme l\'adresse, demande-en une autre.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Nom du fichier, extension comprise (ex. « polycopie.pdf »).'],
                'size' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Taille exacte du fichier, en octets.'],
                'folderId' => ['type' => 'integer', 'description' => 'Dossier de la bibliothèque (file_list) ; la racine sinon.'],
                'sha256' => ['type' => 'string', 'pattern' => '^[0-9a-fA-F]{64}$', 'description' => 'Empreinte SHA-256 du fichier, en hexadécimal. Facultatif : l\'envoi est refusé s\'il ne correspond pas.'],
            ],
            'required' => ['name', 'size'],
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
        $grant = $call->grant ?? throw new McpToolException('Une adresse d\'envoi ne se donne qu\'à travers une connexion du connecteur.');

        $name = trim($call->requiredString('name'));
        if (mb_strlen($name) > 255 || 1 === preg_match('/[\/\\\\\x00-\x1F]/u', $name)) {
            throw new McpToolException('Le nom (« name ») est un nom de fichier de 255 caractères au plus, sans chemin.');
        }

        $size = $call->arguments->int('size');
        if (null === $size || $size < 1) {
            throw new McpToolException('La taille (« size ») est le nombre exact d\'octets du fichier.');
        }

        $sha256 = trim($call->arguments->string('sha256'));
        if ('' !== $sha256 && 1 !== preg_match('/^[0-9a-fA-F]{64}$/', $sha256)) {
            throw new McpToolException('L\'empreinte (« sha256 ») s\'écrit en 64 caractères hexadécimaux.');
        }

        $folder = $this->library->fileFolder($call->optionalId('folderId'));

        $policy = $this->uploadPolicy->policyFor($name);
        $reason = $policy->refusalReason($name, null);
        if (null !== $reason) {
            throw new McpToolException($this->translator->trans($reason, [], 'messages', 'fr'));
        }
        if ($size > $policy->maxSizeInBytes()) {
            throw new McpToolException(\sprintf('Ce fichier dépasse %s, le plafond de la bibliothèque pour ce type de fichier : déposez-le directement dans MonCampus ou réduisez-le.', $policy->maxSize()));
        }
        if (!$this->quota->accepts($call->user, $size)) {
            $refusal = $this->quota->refusal($call->user, $size);

            throw new McpToolException($this->translator->trans($refusal['key'], $refusal['parameters'], 'messages', 'fr'));
        }

        $now = $this->clock->now();
        $secret = OAuthSecret::mint(self::SECRET_PREFIX);
        $slot = new McpUploadSlot($grant, $folder, $name, $size, '' === $sha256 ? null : $sha256, $secret->selector, $secret->verifierHash, $now, $now->modify(\sprintf('+%d minutes', self::LIFETIME_MINUTES)));
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $uploadUrl = $this->links->url('app_mcp_upload', ['secret' => $secret->secret]);

        return McpToolResult::data(
            \sprintf('Adresse d\'envoi prête pour « %s » (%d octets), valable %d minutes et pour un seul envoi : curl -X PUT --data-binary @fichier "%s"', $name, $size, self::LIFETIME_MINUTES, $uploadUrl),
            ['uploadUrl' => $uploadUrl, 'expiresAt' => $slot->getExpiresAt()->format(\DATE_ATOM), 'maxBytes' => $size],
        );
    }
}
