<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a formation makes of a piece: required (it holds back « Complet »), optional (asked, never
 * blocking) or hidden (not asked at all - what was entered stays in « Saisies conservées »).
 */
enum CrmPieceConstraint: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Hidden = 'hidden';

    public function labelKey(): string
    {
        return match ($this) {
            self::Required => 'crmConstraintRequiredLabel',
            self::Optional => 'crmConstraintOptionalLabel',
            self::Hidden => 'crmConstraintHiddenLabel',
        };
    }
}
