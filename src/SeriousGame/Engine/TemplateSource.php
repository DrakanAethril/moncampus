<?php

declare(strict_types=1);

namespace App\SeriousGame\Engine;

use App\SeriousGame\Template\TemplateDefinition;

/**
 * Where the engine finds a game's templates: the validated versions in the database for the
 * students (DatabaseTemplateSource), the files of the repository for the bench and the tests
 * (FileTemplateSource).
 */
interface TemplateSource
{
    /** The version a student is served: the highest validated one. */
    public function current(string $unit): ?TemplateDefinition;

    /** A given version, to replay a question served by it. */
    public function version(string $unit, int $version): ?TemplateDefinition;
}
