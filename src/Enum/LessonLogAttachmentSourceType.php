<?php

namespace App\Enum;

/** How a LessonLogAttachment's content is stored: an S3-backed upload, or an external URL. */
enum LessonLogAttachmentSourceType: string
{
    case Upload = 'upload';
    case Link = 'link';

    public function labelKey(): string
    {
        return match ($this) {
            self::Upload => 'lessonLogAttachmentSourceTypeUploadLabel',
            self::Link => 'lessonLogAttachmentSourceTypeLinkLabel',
        };
    }
}
