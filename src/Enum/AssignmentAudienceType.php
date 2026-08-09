<?php

declare(strict_types=1);

namespace App\Enum;

/** Who an Assignment's submission box is open to - see App\Service\AssignmentAudienceResolver. */
enum AssignmentAudienceType: string
{
    case Program = 'program';
    case Option = 'option';
    case Manual = 'manual';
    case GroupBatch = 'group_batch';

    public function labelKey(): string
    {
        return match ($this) {
            self::Program => 'assignmentAudienceTypeProgramLabel',
            self::Option => 'assignmentAudienceTypeOptionLabel',
            self::Manual => 'assignmentAudienceTypeManualLabel',
            self::GroupBatch => 'assignmentAudienceTypeGroupBatchLabel',
        };
    }

    /**
     * Les quatre segments « au sein de la classe » du 2a, dans l'ordre de la maquette.
     *
     * @return list<self>
     */
    public static function forWizard(): array
    {
        return [self::Program, self::Option, self::Manual, self::GroupBatch];
    }
}
