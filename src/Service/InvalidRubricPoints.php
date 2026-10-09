<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What was typed in a question's box is not a mark this question can take. The reason is the code
 * the entry screen's own request answers with, so the browser and any other caller name the two
 * cases the same way.
 */
final class InvalidRubricPoints extends \DomainException
{
    public const string NOT_A_NUMBER = 'invalid';
    public const string EXCEEDS_MAX_POINTS = 'exceeds_max_points';

    /** @param self::NOT_A_NUMBER|self::EXCEEDS_MAX_POINTS $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
