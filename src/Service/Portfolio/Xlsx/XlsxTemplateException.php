<?php

declare(strict_types=1);

namespace App\Service\Portfolio\Xlsx;

/**
 * The official template could not be read or written. The message is a translation key - the
 * inspection screen names the landmark that was not found.
 */
final class XlsxTemplateException extends \RuntimeException
{
}
