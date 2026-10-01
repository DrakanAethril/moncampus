<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The four groups of the board's dock (design/validated/tableau-virtuel.md, §5), in dock order.
 */
enum ClassBoardWidgetFamily: string
{
    case Time = 'time';
    case ClassGroup = 'class';
    case Ambiance = 'ambiance';
    case Content = 'content';

    public function labelKey(): string
    {
        return match ($this) {
            self::Time => 'classBoardFamilyTimeLabel',
            self::ClassGroup => 'classBoardFamilyClassLabel',
            self::Ambiance => 'classBoardFamilyAmbianceLabel',
            self::Content => 'classBoardFamilyContentLabel',
        };
    }
}
