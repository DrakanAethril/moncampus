<?php

namespace App\Enum;

// The 3 evaluation formats (design's "Type": Écrite/Orale/Pratique cards on the evaluation form).
enum EvaluationType: string
{
    case Written = 'written';
    case Oral = 'oral';
    case Practical = 'practical';

    public function labelKey(): string
    {
        return match ($this) {
            self::Written => 'evaluationTypeWrittenLabel',
            self::Oral => 'evaluationTypeOralLabel',
            self::Practical => 'evaluationTypePracticalLabel',
        };
    }
}
