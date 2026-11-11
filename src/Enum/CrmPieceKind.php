<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What entering a piece asks for. « Choix » is the shipped pieces' radio (two or three values);
 * a piece created in the settings is a « Liste » instead, which says the same thing in a select.
 */
enum CrmPieceKind: string
{
    case Check = 'check';
    case Choice = 'choice';
    case Text = 'text';
    case Amount = 'amount';
    case Period = 'period';
    case List = 'list';

    public function labelKey(): string
    {
        return match ($this) {
            self::Check => 'crmPieceKindCheckLabel',
            self::Choice => 'crmPieceKindChoiceLabel',
            self::Text => 'crmPieceKindTextLabel',
            self::Amount => 'crmPieceKindAmountLabel',
            self::Period => 'crmPieceKindPeriodLabel',
            self::List => 'crmPieceKindListLabel',
        };
    }

    public function hasChoices(): bool
    {
        return self::Choice === $this || self::List === $this;
    }
}
