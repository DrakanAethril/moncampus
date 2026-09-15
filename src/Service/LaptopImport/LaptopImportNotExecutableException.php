<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

/**
 * The analysis handed to LaptopImportExecutor no longer says the file can be imported.
 *
 * Raised rather than returned because it means the world moved between the verification screen and
 * the click that confirmed it - somebody else added one of these machines in the meantime. The
 * caller shows the analysis again, which by then says so.
 */
final class LaptopImportNotExecutableException extends \RuntimeException
{
}
