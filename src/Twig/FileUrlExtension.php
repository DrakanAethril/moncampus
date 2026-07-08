<?php

namespace App\Twig;

use App\Service\FileUploadService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Generic S3 key -> URL resolution for templates that don't have a dedicated Twig function of
// their own (see App\Twig\AvatarExtension for the User-specific one) - first used by the Livret
// Alternant Program Info tab to preview an already-uploaded cover-page/calendar image.
class FileUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('file_url', $this->getFileUrl(...)),
        ];
    }

    public function getFileUrl(?string $key): ?string
    {
        if (null === $key) {
            return null;
        }

        return $this->fileUploadService->url($key);
    }
}
