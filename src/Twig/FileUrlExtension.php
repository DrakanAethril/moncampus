<?php

declare(strict_types=1);

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

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('file_url', $this->getFileUrl(...)),
            new TwigFunction('download_url', $this->getDownloadUrl(...)),
        ];
    }

    public function getFileUrl(?string $key): ?string
    {
        if (null === $key) {
            return null;
        }

        return $this->fileUploadService->url($key);
    }

    /**
     * The address for a link a reader **saves**, as opposed to one a page displays.
     *
     * file_url() is the wrong function for an `<a href>` on an attachment: it hands over the CDN
     * address, and the object behind it is stored under a random key, so the file lands in the
     * reader's Téléchargements folder called `ca7cfc9b4cea16f5ec59a50fdd001830.pdf`. This one
     * carries the name the row already displays next to the link.
     *
     * It stays a separate function rather than a second argument to file_url() because the two
     * really are different addresses - this one is signed, so it must never be put in an `<img>`,
     * a `<video>`, or anything a stored document embeds: those want the cacheable, permanent CDN
     * address, and a signature inside saved HTML would rot.
     */
    public function getDownloadUrl(?string $key, ?string $name): ?string
    {
        if (null === $key) {
            return null;
        }

        return $this->fileUploadService->downloadUrl($key, $name ?? '', FileUploadService::PAGE_LIFETIME);
    }
}
