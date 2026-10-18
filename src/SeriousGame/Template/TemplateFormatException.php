<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

/**
 * A template document that does not follow `moncampus-gabarit/1` - refused whole, at the first
 * error, with the path of the faulty element so that whoever wrote it (an administrator, or Claude
 * through the connector) can correct it and send it again.
 */
final class TemplateFormatException extends \InvalidArgumentException
{
    public function __construct(public readonly string $path, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(('' === $path ? '' : $path.' : ').$reason, 0, $previous);
    }
}
