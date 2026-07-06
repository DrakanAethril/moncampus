<?php

namespace App\Enum;

/**
 * How a ProgramFinancialItem's quantity is determined: per hour of a given lesson type, per
 * student in the program, or a manually entered quantity.
 */
enum FinancialItemSource: string
{
    case Lesson = 'lesson';
    case Student = 'student';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::Lesson => 'financialItemSourceLessonLabel',
            self::Student => 'financialItemSourceStudentLabel',
            self::Manual => 'financialItemSourceManualLabel',
        };
    }
}
