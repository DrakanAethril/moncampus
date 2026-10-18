<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

/**
 * 200 draws in a row rejected by the constraints: the template asks for something its parameters
 * almost never give. The bench fails on it; a student never meets it, since only a template whose
 * bench is green is validated.
 */
final class TemplateExhaustedException extends \RuntimeException
{
    public function __construct(public readonly string $unit, public readonly int $seed)
    {
        parent::__construct(\sprintf('Le gabarit « %s » n’a pas donné de question en %d tirages (graine %d).', $unit, TemplateInterpreter::MAX_ATTEMPTS, $seed));
    }
}
