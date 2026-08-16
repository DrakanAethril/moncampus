<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a support attached to an assignment comes from: a file uploaded by the teacher, or a link to
 * an outside resource. Same distinction as LessonLogAttachmentSourceType, on another carrier - the
 * two tables stay separate, an assignment support not being a séance document.
 */
enum AssignmentAttachmentSourceType: string
{
    case Upload = 'upload';
    case Link = 'link';
}
