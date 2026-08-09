<?php

namespace App\Monolog;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts error-and-worse log records to the support Discord webhook - the same one
 * App\Service\TicketDiscordNotifier uses for tickets.
 *
 * Production had no alerting at all before this: Monolog writes JSON to stderr, i.e. into the
 * server's Docker logs, so a 500 was only ever known when somebody bothered to report it.
 *
 * Wired in config/packages/monolog.yaml under when@prod only, behind the same fingers_crossed +
 * excluded_http_codes gate as the stderr handler (Symfony logs a plain 404 at *error* level, so
 * without that gate every scanner bot would ring the channel), with a "filter" handler in between
 * so only the triggering record is posted and not the 50 buffered debug ones behind it.
 */
class DiscordWebhookHandler extends AbstractProcessingHandler
{
    private const string WEBHOOK_URL_TEMPLATE = 'https://discord.com/api/webhooks/%s/%s';

    // Throttling. A production incident is rarely one error: it is the same error a few hundred
    // times, or a burst of different ones, and Discord rate-limits a webhook long before that
    // stops being useful to read. The state is per-process, which under FrankenPHP worker mode
    // means it genuinely spans requests rather than resetting on every one.
    private const int SIGNATURE_COOLDOWN_SECONDS = 300;
    private const int BURST_WINDOW_SECONDS = 60;
    private const int MAX_SENDS_PER_WINDOW = 10;
    private const int MAX_TRACKED_SIGNATURES = 500;

    // The .env placeholders (see the symfony/discord-notifier block there): syntactically valid so
    // the Notifier transport can be built, but not a real webhook.
    private const string UNCONFIGURED_TOKEN = 'unconfigured';
    private const string UNCONFIGURED_ID = '0';

    private bool $sending = false;

    /** @var array<string, int> signature => timestamp of the last alert sent for it */
    private array $lastSentAt = [];

    /** @var list<int> timestamps of the alerts sent within the burst window */
    private array $recentSends = [];

    public function __construct(
        private readonly DiscordAlertFormatter $alertFormatter,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'DISCORD_WEBHOOK_ID')] private readonly string $webhookId,
        #[Autowire(env: 'DISCORD_WEBHOOK_TOKEN')] private readonly string $webhookToken,
    ) {
        parent::__construct(Level::Error);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->sending || !$this->isConfigured()) {
            return;
        }

        $now = $record->datetime->getTimestamp();
        if (!$this->passesThrottle($this->signature($record), $now)) {
            return;
        }

        // Re-entrancy guard: whatever the HTTP call logs on its way out - and anything logged at
        // error level by an event listener while we are blocked here - must not come back into
        // this handler and loop.
        $this->sending = true;

        try {
            // getStatusCode() forces the otherwise-lazy request to complete; the response body is
            // of no interest, the call is only there so the alert is actually on the wire before
            // the worker moves on.
            $this->httpClient->request('POST', \sprintf(self::WEBHOOK_URL_TEMPLATE, $this->webhookId, $this->webhookToken), [
                'json' => ['content' => (string) $record->formatted],
            ])->getStatusCode();
        } catch (\Throwable) {
            // A best-effort alert must never turn a logged error into a second, louder failure.
            // The record reached stderr through the other handler either way.
        } finally {
            $this->sending = false;
        }
    }

    #[\Override]
    protected function getDefaultFormatter(): FormatterInterface
    {
        return $this->alertFormatter;
    }

    private function isConfigured(): bool
    {
        return '' !== $this->webhookId
            && self::UNCONFIGURED_ID !== $this->webhookId
            && '' !== $this->webhookToken
            && self::UNCONFIGURED_TOKEN !== $this->webhookToken;
    }

    private function signature(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;

        return md5(implode('|', [
            $record->level->value,
            $record->channel,
            $record->message,
            $exception instanceof \Throwable ? $exception::class.':'.$exception->getFile().':'.$exception->getLine() : '',
        ]));
    }

    private function passesThrottle(string $signature, int $now): bool
    {
        $this->recentSends = array_values(array_filter(
            $this->recentSends,
            static fn (int $sentAt): bool => $sentAt > $now - self::BURST_WINDOW_SECONDS,
        ));

        if (\count($this->recentSends) >= self::MAX_SENDS_PER_WINDOW) {
            return false;
        }

        $lastSentAt = $this->lastSentAt[$signature] ?? null;
        if (null !== $lastSentAt && $lastSentAt > $now - self::SIGNATURE_COOLDOWN_SECONDS) {
            return false;
        }

        // Worker processes are long-lived, so this map has to be swept rather than grown forever.
        if (\count($this->lastSentAt) >= self::MAX_TRACKED_SIGNATURES) {
            $this->lastSentAt = array_filter(
                $this->lastSentAt,
                static fn (int $sentAt): bool => $sentAt > $now - self::SIGNATURE_COOLDOWN_SECONDS,
            );
        }

        $this->lastSentAt[$signature] = $now;
        $this->recentSends[] = $now;

        return true;
    }
}
