<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * The types of answer a question template may ask (design/validated/serious-games.md §5.2), each
 * with its checker in AnswerCheckers. Each belongs to a **kind** - `short` (typed) or `choice`
 * (chosen among propositions), `order` (items put in order) or `spot` (the wrong word of a line
 * pointed at) - and a game accepts the kinds an administrator ticks for it: L'Observatoire only
 * `short`, since the épreuve dropped its QCM; Les Tablettes de Pompéi `short` and `choice`.
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
    /** A word or a short text, compared without case nor surrounding spaces; a value may list several accepted texts. */
    case Text = 'text';
    /** A sequence of letters (a strand of DNA or RNA): spaces, dashes and case ignored. */
    case Sequence = 'sequence';
    /** A Latin form, compared without case, macrons, j or v; a value may list several accepted forms. */
    case Latin = 'latin';
    /** A table of Latin forms to fill, one field per label (the six cases of a number). */
    case Forms = 'forms';
    /** One proposition chosen among the answer and the question's typical wrong answers (a QCM). */
    case Choice = 'choice';
    /** Items to put in order (a food chain, the words of a question): the game shows them shuffled. */
    case Order = 'order';
    /** The wrong word of a line, pointed at - or the line declared correct. */
    case Spot = 'spot';

    public const string KIND_SHORT = 'short';
    public const string KIND_CHOICE = 'choice';
    public const string KIND_ORDER = 'order';
    public const string KIND_SPOT = 'spot';

    /** The kind of answer this type is (§1.3): typed, chosen among propositions, ordered or pointed at. */
    public function kind(): string
    {
        return match ($this) {
            self::Choice => self::KIND_CHOICE,
            self::Order => self::KIND_ORDER,
            self::Spot => self::KIND_SPOT,
            default => self::KIND_SHORT,
        };
    }

    /**
     * Whether what a question displays as its answer is also what a student could send: true for
     * every typed type; false for items put in order (displayed « a → b → c ») and a word pointed
     * at (displayed as the word), which the game sends in their own form.
     */
    public function displayIsSent(): bool
    {
        return !\in_array($this, [self::Order, self::Spot], true);
    }

    /** Shown to the student under the field: what to type. */
    public function labelKey(): string
    {
        return 'seriousGameAnswerType'.ucfirst($this->value);
    }
}
