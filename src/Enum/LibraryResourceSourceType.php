<?php

namespace App\Enum;

/** How a LibraryResource's content is stored: an S3-backed upload, or an external URL. */
enum LibraryResourceSourceType: string
{
    case Upload = 'upload';
    case Link = 'link';

    public function labelKey(): string
    {
        return match ($this) {
            self::Upload => 'libraryResourceSourceTypeUploadLabel',
            self::Link => 'libraryResourceSourceTypeLinkLabel',
        };
    }
}
