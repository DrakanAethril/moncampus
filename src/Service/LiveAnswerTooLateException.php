<?php

declare(strict_types=1);

namespace App\Service;

/**
 * An answer that reached the server once its question was over - the clock ran out or the host
 * closed it. Told apart from the other refusals so that the phone can say « Trop tard » instead of
 * showing an answer as sent that was never counted.
 */
final class LiveAnswerTooLateException extends LiveSessionStateException
{
}
