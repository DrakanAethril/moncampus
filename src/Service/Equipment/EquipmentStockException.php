<?php

declare(strict_types=1);

namespace App\Service\Equipment;

/**
 * A movement the stock cannot honour - more cables taken than there are, a mouse put into service
 * twice. The message is a translation key, shown to whoever tried.
 */
final class EquipmentStockException extends \DomainException
{
}
