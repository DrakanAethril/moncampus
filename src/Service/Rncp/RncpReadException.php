<?php

declare(strict_types=1);

namespace App\Service\Rncp;

/**
 * France compétences' export could not be found, fetched or read. The message is French and is
 * shown as is on the import screen, next to « Relancer ».
 */
final class RncpReadException extends \RuntimeException
{
}
