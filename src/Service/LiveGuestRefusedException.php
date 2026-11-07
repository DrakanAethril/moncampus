<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A guest's pseudonym refused (App\Service\QuizLive\LiveNickname): the message is the translation
 * key of the reason the guest page shows.
 */
class LiveGuestRefusedException extends \RuntimeException
{
}
