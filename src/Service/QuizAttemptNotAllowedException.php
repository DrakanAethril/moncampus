<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown by QuizAttemptStarter::startOrResume() when the student may not open a new attempt because
 * the QuizInstance is outside its opens/closes window. Web turns it into a 403, the mobile API into
 * a JSON error - hence an exception rather than a null return neither caller could tell apart from
 * "nothing to resume".
 */
class QuizAttemptNotAllowedException extends \RuntimeException
{
}
