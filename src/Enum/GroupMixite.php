<?php

namespace App\Enum;

/**
 * Option-mixing objective on the Création de groupes tool (design/design_campus_manager/
 * PROMPT_CLAUDE_CODE_groupes.md, section 3) - a soft placement preference, not a hard constraint
 * (unlike separate/together pairs): App\Service\GroupCreationService always fills the
 * least-populated eligible group first when it can't do better against this objective.
 */
enum GroupMixite: string
{
    case Free = 'libre';
    case Mixed = 'mixte';
    case Homogeneous = 'homogene';
}
