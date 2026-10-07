<?php

declare(strict_types=1);

namespace App\Service\Wall;

/**
 * Something a wall refuses for a reason its author can act on - an empty title, a link that is not
 * one, a file of the wrong kind. The message is a translation key (or, for an upload, the sentence
 * the upload policy already wrote); the controllers answer it as a 422 with that sentence.
 */
final class WallRefusal extends \DomainException
{
    /** @param array<string, string> $parameters */
    public function __construct(string $messageKey, public readonly array $parameters = [], public readonly bool $translated = false)
    {
        parent::__construct($messageKey);
    }
}
