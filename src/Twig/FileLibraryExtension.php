<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\ByteSize;
use App\Service\UploadPolicy;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The three questions a library row asks about a file name, answered once rather than in Twig.
 *
 * - **`byte_size`** - the size as a screen writes it, through App\Service\ByteSize, so the table, the
 *   quota bar and the admin's card cannot spell the same number three ways;
 * - **`file_library_previewable`** - whether the row's menu offers *Aperçu* at all. Nothing is ever
 *   generated (no thumbnail, no PDF first page): the rule is "what the browser already opens", which
 *   is `UploadPolicy::servesInline()` plus the audio and video extensions the two media tools already
 *   ship players for;
 * - **`file_library_family`** - which colour the extension tile takes (mockup 1).
 */
class FileLibraryExtension extends AbstractExtension
{
    /**
     * The families the extension tile is coloured by, and the extensions in each. Anything unknown
     * falls back to the neutral tile rather than to a wrong colour.
     *
     * @var array<string, list<string>>
     */
    private const array FAMILIES = [
        'pdf' => ['pdf'],
        'img' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'tif', 'tiff', 'heic'],
        'vid' => ['mp4', 'webm', 'mov'],
        'aud' => ['mp3', 'm4a', 'wav', 'ogg', 'opus', 'flac'],
        'doc' => ['doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'ppt', 'pptx', 'odp', 'xls', 'xlsx', 'ods', 'csv'],
        'zip' => ['zip', '7z', 'tar', 'gz', 'tgz'],
    ];

    /** Played rather than displayed, but still "opened by the browser" - hence previewable. */
    private const array PLAYABLE = ['mp4', 'webm', 'mov', 'mp3', 'm4a', 'wav', 'ogg', 'opus', 'flac'];

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('byte_size', ByteSize::format(...)),
            new TwigFunction('file_library_previewable', $this->isPreviewable(...)),
            new TwigFunction('file_library_family', $this->familyOf(...)),
        ];
    }

    public function isPreviewable(string $filename): bool
    {
        return UploadPolicy::servesInline($filename) || \in_array($this->extensionOf($filename), self::PLAYABLE, true);
    }

    public function familyOf(string $filename): string
    {
        $extension = $this->extensionOf($filename);

        foreach (self::FAMILIES as $family => $extensions) {
            if (\in_array($extension, $extensions, true)) {
                return $family;
            }
        }

        return 'other';
    }

    private function extensionOf(string $filename): string
    {
        return mb_strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
    }
}
