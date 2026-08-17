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

    /**
     * A file taken from the teacher's own library (design/validated/file-library.md).
     *
     * It reads exactly like an Upload - the row carries its own storage key, copied from the node -
     * and it is a third case rather than a flag because what changes is *where it came from*, which
     * is what the row's badge and the usage panel both need to know. Deleting the library file
     * removes these rows; deleting an Upload's file does not, there being nothing else to remove.
     */
    case Library = 'library';
}
