<?php

namespace App\Monolog;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Turns a log record into the one-message Discord alert posted by DiscordWebhookHandler.
 *
 * Kept apart from the handler for the usual Monolog reason - a formatter is a pure function of the
 * record, so the wording and the truncation rules are unit-testable without an HTTP client (see
 * tests/Monolog/DiscordAlertFormatterTest.php).
 */
class DiscordAlertFormatter implements FormatterInterface
{
    // Discord rejects a message over 2000 characters outright; stay clear of the limit rather than
    // discovering it on the one alert that carries a long stack-trace-ish message.
    private const int MAX_CONTENT_LENGTH = 1900;
    private const int MAX_MESSAGE_LENGTH = 900;

    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire(param: 'kernel.environment')] private readonly string $environment,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {
    }

    public function format(LogRecord $record): string
    {
        // Same "[DEV] " prefix as App\Service\TicketDiscordNotifier, for the same reason: dev and
        // production may legitimately point at the same webhook.
        $lines = [\sprintf(
            '%s🚨 %s — canal %s',
            'prod' === $this->environment ? '' : '[DEV] ',
            $record->level->getName(),
            $record->channel,
        )];

        $lines[] = mb_strimwidth(trim($record->message), 0, self::MAX_MESSAGE_LENGTH, '…');

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            // Symfony's own message only carries basename(), which is ambiguous across the ~75
            // controllers - the project-relative path is what makes the alert actionable.
            $lines[] = \sprintf(
                'Exception : %s — %s:%d',
                $exception::class,
                $this->relativePath($exception->getFile()),
                $exception->getLine(),
            );
        }

        $request = $this->requestStack->getMainRequest();
        if (null !== $request) {
            // Deliberately without the query string: magic-login tokens (MagicLoginToken) and
            // CSRF tokens travel there, and this message lands in a channel humans can read.
            $lines[] = \sprintf(
                'Requête : %s %s',
                $request->getMethod(),
                $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo(),
            );
        }

        return mb_strimwidth(implode("\n", $lines), 0, self::MAX_CONTENT_LENGTH, '…');
    }

    /**
     * @param LogRecord[] $records
     */
    public function formatBatch(array $records): string
    {
        return implode("\n\n", array_map($this->format(...), $records));
    }

    private function relativePath(string $path): string
    {
        $prefix = $this->projectDir.'/';

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
    }
}
