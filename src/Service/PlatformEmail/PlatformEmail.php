<?php

declare(strict_types=1);

namespace App\Service\PlatformEmail;

use App\Enum\PlatformEmailGroup;

/**
 * One mail the platform sends, as UFA > Configuration > Emails shows it: the template the sender
 * really uses, and a context where everything that changes from one send to the next is a
 * placeholder ($PERIODE, $ALTERNANT...) instead of a value.
 */
final readonly class PlatformEmail
{
    /**
     * @param array<string, mixed>  $context   what the sender passes to the template, placeholders in place of the values
     * @param array<string, string> $variables placeholder => translation key of what it stands for
     */
    public function __construct(
        public string $key,
        public PlatformEmailGroup $group,
        public string $titleKey,
        public string $whenKey,
        public string $template,
        public array $context = [],
        public array $variables = [],
    ) {
    }
}
