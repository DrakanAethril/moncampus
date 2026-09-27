<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A file App\Service\FileLibraryWriter would not put in the library - a type the platform refuses,
 * a quota reached, a virus found. The message is already translated: it is shown as it is.
 */
final class FileLibraryWriteRefused extends \RuntimeException
{
}
