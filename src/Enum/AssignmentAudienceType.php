<?php

namespace App\Enum;

/** Who an Assignment's submission box is open to - see App\Service\AssignmentAudienceResolver. */
enum AssignmentAudienceType: string
{
    case Program = 'program';
    case Option = 'option';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::Program => 'assignmentAudienceTypeProgramLabel',
            self::Option => 'assignmentAudienceTypeOptionLabel',
            self::Manual => 'assignmentAudienceTypeManualLabel',
        };
    }
}
