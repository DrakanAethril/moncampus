<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\McpUploadSlot;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\McpUploadSlotRepository;
use App\Security\FeatureAccess;
use App\Security\McpUploadSlotAuthenticator;
use App\Security\Voter\FileLibraryVoter;
use App\Service\FileLibraryWriter;
use App\Service\FileLibraryWriteRefused;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The other half of `file_upload_url` (App\Mcp\Tool\FileUploadUrlTool): the address Claude's sandbox
 * sends one file to, with `curl -X PUT --data-binary @file "<uploadUrl>"`.
 *
 * The `mcp_upload` firewall has already turned the secret into the teacher
 * (App\Security\McpUploadSlotAuthenticator), so what follows asks the ordinary questions: the
 * features lit for them, the folder through the library's voter, and the file through
 * App\Service\FileLibraryWriter - type, quota, antivirus, exactly as an upload from the screen.
 *
 * Every answer is JSON, refusals included: the caller is a model reading curl's output, and a body
 * it cannot read is a body it reports as a success.
 *
 * The body is streamed to a temporary file and never held as a string: an address may carry a
 * 200 Mo video. It is read up to one byte past the size the address was made for, which is how a
 * longer body is told apart without reading it all.
 */
#[RequiresFeature(Feature::FileLibrary)]
class McpUploadController extends AbstractController
{
    #[Route(path: '/mcp/uploads/{secret}', name: 'app_mcp_upload', requirements: ['secret' => '[0-9a-z_]+'], methods: ['PUT'])]
    public function upload(Request $request, McpUploadSlotRepository $slots, FeatureAccess $features, FileLibraryWriter $writer, EntityManagerInterface $entityManager, ClockInterface $clock): JsonResponse
    {
        $slot = $request->attributes->get(McpUploadSlotAuthenticator::SLOT_ATTRIBUTE);
        $user = $this->getUser();
        \assert($slot instanceof McpUploadSlot && $user instanceof User);

        // The method's feature replaces the class's (App\Attribute\RequiresFeature), so the
        // connector's own is asked here: switching it off closes its pending addresses too.
        if (!$features->isEnabled(Feature::ClaudeConnector)) {
            throw $this->createNotFoundException();
        }

        if (!$slots->claim($slot, $clock->now())) {
            return $this->refuse('Cette adresse d\'envoi vient d\'être utilisée : demande-en une autre avec file_upload_url.', Response::HTTP_NOT_FOUND);
        }

        $folder = $slot->getFolder();
        if (null !== $folder && ($folder->isDeleted() || !$this->isGranted(FileLibraryVoter::EDIT, $folder))) {
            return $this->refuse('Le dossier de destination n\'est plus dans la bibliothèque.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp-upload-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary file.');
        }

        try {
            $received = $this->receive($request, $path, $slot->getSizeBytes() + 1);
            if ($received !== $slot->getSizeBytes()) {
                return $this->refuse(\sprintf('Reçu %d octets pour %d annoncés : l\'envoi est incomplet ou ce n\'est pas le même fichier.', $received, $slot->getSizeBytes()));
            }

            $sha256 = (string) hash_file('sha256', $path);
            if (null !== $slot->getSha256() && !hash_equals($slot->getSha256(), $sha256)) {
                return $this->refuse('L\'empreinte SHA-256 du fichier reçu ne correspond pas à celle annoncée.');
            }

            try {
                $file = $writer->writeFile($user, $folder, $slot->getName(), $path);
            } catch (FileLibraryWriteRefused $refusal) {
                return $this->refuse($refusal->getMessage());
            }

            $slot->setFile($file);
            $entityManager->flush();

            return self::readable([
                'fileId' => $file->getId(),
                'name' => $file->getName(),
                'size' => $file->getSizeBytes(),
                'sha256' => $sha256,
            ], Response::HTTP_CREATED);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Copies the body to $path, $limit bytes at most.
     *
     * @return int how many bytes were written
     */
    private function receive(Request $request, string $path, int $limit): int
    {
        $body = $request->getContent(true);
        $target = fopen($path, 'w') ?: throw new \RuntimeException(\sprintf('Could not open "%s" for writing.', $path));

        try {
            $copied = stream_copy_to_stream($body, $target, $limit);
        } finally {
            fclose($target);
        }

        return false === $copied ? 0 : $copied;
    }

    private function refuse(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return self::readable(['error' => $message], $status);
    }

    /**
     * JSON a person reads in a terminal: accents and apostrophes as they are, not `\u00e9` and
     * `\u0027` - the model repeats curl's output to the teacher.
     *
     * @param array<string, mixed> $data
     */
    public static function readable(array $data, int $status): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $response->setData($data);
    }
}
