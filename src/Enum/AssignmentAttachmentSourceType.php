<?php

namespace App\Enum;

/**
 * D'où vient un support attaché à un travail : un fichier déposé par l'enseignant, ou un lien vers
 * une ressource extérieure. Même distinction que LessonLogAttachmentSourceType, sur un autre
 * porteur - les deux tables restent séparées, un support de travail n'étant pas un document de
 * séance.
 */
enum AssignmentAttachmentSourceType: string
{
    case Upload = 'upload';
    case Link = 'link';
}
