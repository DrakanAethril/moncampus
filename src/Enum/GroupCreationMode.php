<?php

namespace App\Enum;

/**
 * "Par taille" vs "Par nombre" on the Création de groupes tool (design/design_campus_manager/
 * PROMPT_CLAUDE_CODE_groupes.md, section 1) - which of the stepper's two meanings the value
 * carries: a target group size, or a target group count.
 */
enum GroupCreationMode: string
{
    case Size = 'taille';
    case Count = 'nombre';
}
