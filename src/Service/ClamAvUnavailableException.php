<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when scanning is configured but could not be carried out - clamd unreachable, the socket
 * timing out, an unparseable reply, or an unreadable temp file.
 *
 * Mirrors App\Service\GotenbergUnavailableException's contract deliberately: one exception type per
 * external service that can be down, so a caller degrades on purpose rather than by accident. The
 * difference is what "degrade" means here - a Gotenberg outage loses a PDF, an antivirus outage
 * must **refuse the upload**. Failing open would leave the platform believing every stored file was
 * scanned when some were not, which is worse than not scanning at all.
 */
class ClamAvUnavailableException extends \RuntimeException
{
}
