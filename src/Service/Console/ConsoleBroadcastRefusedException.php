<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * This console may not broadcast, and the reason is one of three.
 *
 * It has no batch (an administrator's console on a machine outside any deployment), it has become
 * somebody else (§7.4 - what would go out is no longer the same command from one machine to the
 * next), or the line is empty. All three are refusals rather than failures, and all three carry a
 * translation key: they are shown to somebody about to send a command to a whole class.
 */
class ConsoleBroadcastRefusedException extends \RuntimeException
{
}
