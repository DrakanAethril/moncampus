<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

/**
 * A page address that cannot be given. The message is a translation key: it is shown to the
 * teacher on « Ma page », and read by the Claude connector's model.
 */
final class OnlineCourseHandleRefused extends \RuntimeException
{
}
