<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it was told, for a test to read back: level, message template (the
 * placeholders not filled in) and context.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /** @param array<array-key, mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : '',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
