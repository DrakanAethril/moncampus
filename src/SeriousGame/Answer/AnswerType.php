<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * The types of answer a question template may ask (design/validated/serious-games.md §5.2), each
 * with its checker in AnswerCheckers. Every one of them is a **short** answer - typed, never
 * chosen: L'Observatoire accepts no other kind since the épreuve dropped its QCM.
 */
enum AnswerType: string
{
    /** A number: comma or point, « 15 % » or « 15 », a fraction typed for a decimal. */
    case Number = 'number';
    /** A number expected as a fraction; any equivalent fraction or decimal is accepted. */
    case Fraction = 'fraction';
    /** A single power (« a^6 », « 2^7 »); the value is the natural logarithm, « a » read as 1,7. */
    case Power = 'power';
    /** An expression in x, checked by evaluation at five points. */
    case Expression = 'expression';
    /** An expression in x with a required shape: `developed`, `factored` or `factoredMax`. */
    case Polynomial = 'polynomial';
    /** A set of numbers, « ; » between them, ∅ for none. */
    case Set = 'set';
    /** An interval, « ]−∞ ; 4] » or an inequality « x ≤ 4 ». */
    case Interval = 'interval';
    /** A scientific notation a × 10^n, with 1 ≤ a < 10. */
    case Scientific = 'scientific';
    /** A formula in several letters (« isoler une variable »), checked by substitution. */
    case Formula = 'formula';
    /** A word or a short text, compared without case nor surrounding spaces. */
    case Text = 'text';

    /** The kind of answer this type is - only `short` for now (§1.3). */
    public function kind(): string
    {
        return 'short';
    }

    /** Shown to the student under the field: what to type. */
    public function labelKey(): string
    {
        return 'seriousGameAnswerType'.ucfirst($this->value);
    }
}
