<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * One « Visa » cell of the printed booklet. Every part has two evaluator lines; the synthesis adds
 * the training organisation's representative, whose visa closes the booklet.
 */
enum EcfVisaSlot: string
{
    case Evaluator1 = 'evaluator_1';
    case Evaluator2 = 'evaluator_2';
    case Representative = 'representative';

    /** @return list<self> */
    public static function forPart(EcfPart $part): array
    {
        return EcfPart::Synthesis === $part
            ? [self::Evaluator1, self::Evaluator2, self::Representative]
            : [self::Evaluator1, self::Evaluator2];
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Evaluator1 => 'ecfVisaSlotEvaluator1Label',
            self::Evaluator2 => 'ecfVisaSlotEvaluator2Label',
            self::Representative => 'ecfVisaSlotRepresentativeLabel',
        };
    }
}
