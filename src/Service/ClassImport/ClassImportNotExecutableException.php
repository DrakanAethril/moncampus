<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * The analysis handed to App\Service\ClassImport\ClassImportExecutor is no longer one that may be
 * written - a blocking finding, an open decision, or an account that vanished between the
 * verification screen and the confirmation.
 *
 * Always a refusal of the whole file, never of the offending line: an import that half-wrote a
 * class is the state nobody knows how to get out of.
 */
final class ClassImportNotExecutableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The analysis is no longer importable.');
    }
}
