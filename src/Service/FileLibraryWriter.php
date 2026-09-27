<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Validator\AllowedUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bytes produced on the server - a PDF rendered from a course, a small file handed over by the
 * Claude connector - put into a teacher's bibliothèque de fichiers **through the same four gates as
 * an upload**: the platform's list of accepted types for that name (App\Validator\AllowedUpload,
 * which also sniffs the content), the teacher's quota, the antivirus (inside
 * App\Service\FileUploadService::upload()), and the library's own naming rule.
 *
 * Not shared with App\Controller\FileLibrary\FileLibraryUploadController, and deliberately: that one
 * receives a *staged* upload whose bytes are already in the bucket, checked when they were staged;
 * this one receives bytes that exist nowhere yet. The gates are the same services either way.
 *
 * Nothing flushes here - the caller owns its unit of work.
 */
final readonly class FileLibraryWriter
{
    public function __construct(
        private ValidatorInterface $validator,
        private FileLibraryUploadValidator $uploadPolicy,
        private FileLibraryQuota $quota,
        private FileUploadService $uploads,
        private FileLibraryNodeManager $nodes,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws FileLibraryWriteRefused
     */
    public function write(User $owner, ?FileLibraryNode $folder, string $fileName, string $bytes): FileLibraryNode
    {
        $path = tempnam(sys_get_temp_dir(), 'library-write-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary file.');
        }

        try {
            file_put_contents($path, $bytes);
            $file = new UploadedFile($path, $fileName, null, null, true);

            $violations = $this->validator->validate($file, new AllowedUpload($this->uploadPolicy->policyFor($fileName)));
            if ($violations->count() > 0) {
                throw new FileLibraryWriteRefused((string) $violations->get(0)->getMessage());
            }

            $size = \strlen($bytes);
            if (!$this->quota->accepts($owner, $size)) {
                $refusal = $this->quota->refusal($owner, $size);

                throw new FileLibraryWriteRefused($this->translator->trans($refusal['key'], $refusal['parameters']));
            }

            $extension = strtolower(pathinfo($fileName, \PATHINFO_EXTENSION));
            try {
                $key = $this->uploads->upload(
                    FileLibraryNodeManager::UPLOAD_PREFIX,
                    bin2hex(random_bytes(16)).('' === $extension ? '' : '.'.$extension),
                    $file,
                );
            } catch (InfectedUploadException $exception) {
                throw new FileLibraryWriteRefused($this->translator->trans('fileLibraryWriteInfectedMessage', ['%name%' => $fileName]), 0, $exception);
            } catch (ClamAvUnavailableException $exception) {
                throw new FileLibraryWriteRefused($this->translator->trans('fileLibraryWriteScanUnavailableMessage'), 0, $exception);
            }

            return $this->nodes->createFile($owner, $folder, $fileName, $key, $fileName, $file->getMimeType() ?? 'application/octet-stream', $size);
        } finally {
            @unlink($path);
        }
    }
}
