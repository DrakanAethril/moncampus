<?php

declare(strict_types=1);

namespace App\Service\PlatformEmail;

/** What PlatformEmailPreviewer hands the screen: the subject as text, the mail as a whole HTML document. */
final readonly class PlatformEmailPreview
{
    public function __construct(
        public string $subject,
        public string $html,
    ) {
    }
}
