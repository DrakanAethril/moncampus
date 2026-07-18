<?php

namespace App\Service;

/**
 * Thrown by QuizLiveSessionService on any invalid state transition (joining a session that already
 * started, answering after the timer/host closed the question, advancing from a terminal state,
 * etc.) - controllers catch this and turn it into a flash message/JSON error, never a raw 500.
 */
class LiveSessionStateException extends \RuntimeException
{
}
