<?php

declare(strict_types=1);

namespace App\Tests\Monolog;

use App\Monolog\DiscordAlertFormatter;
use App\Monolog\DiscordWebhookHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The three things that decide whether this alerting is worth having: it posts, it does not turn
 * an incident into a flood, and an unconfigured webhook costs nothing.
 */
class DiscordWebhookHandlerTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    public function testItPostsTheAlertToTheWebhook(): void
    {
        $this->handler()->handle($this->record('Uncaught PHP Exception RuntimeException: "boom"'));

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://discord.com/api/webhooks/12345/s3cr3t', $this->requests[0]['url']);
        self::assertStringContainsString('Uncaught PHP Exception RuntimeException', $this->decodedBody(0)['content']);
    }

    public function testThePostedAlertNamesTheFailingCommandRatherThanItsPlaceholder(): void
    {
        $this->handler()->handle($this->record(
            'Error thrown while running command "{command}". Message: "{message}"',
            context: ['command' => 'app:mail:consume-inbound', 'message' => 'Connection refused'],
        ));

        self::assertStringContainsString('command "app:mail:consume-inbound"', $this->decodedBody(0)['content']);
    }

    public function testTwoCommandsFailingTheSameWayShareOneCooldown(): void
    {
        // The throttle reads the message *template*, so the interpolation the formatter does must
        // not turn one incident into one alert per command.
        $handler = $this->handler();
        $start = new \DateTimeImmutable('2026-08-09 10:00:00');
        $template = 'Error thrown while running command "{command}". Message: "{message}"';

        $handler->handle($this->record($template, $start, ['command' => 'app:mail:consume-inbound', 'message' => 'Connection refused']));
        $handler->handle($this->record($template, $start->modify('+1 second'), ['command' => 'app:vm-batch:advance', 'message' => 'Connection refused']));

        self::assertCount(1, $this->requests);
    }

    public function testTheSameErrorRepeatingIsPostedOnceUntilItsCooldownExpires(): void
    {
        $handler = $this->handler();
        $start = new \DateTimeImmutable('2026-08-09 10:00:00');

        $handler->handle($this->record('Boom', $start));
        $handler->handle($this->record('Boom', $start->modify('+1 second')));
        $handler->handle($this->record('Boom', $start->modify('+2 minutes')));
        $handler->handle($this->record('Boom', $start->modify('+6 minutes')));

        self::assertCount(2, $this->requests);
    }

    public function testABurstOfDistinctErrorsIsCappedRatherThanRateLimitedByDiscord(): void
    {
        $handler = $this->handler();
        $start = new \DateTimeImmutable('2026-08-09 10:00:00');

        for ($i = 0; $i < 25; ++$i) {
            $handler->handle($this->record('Boom '.$i, $start->modify("+{$i} seconds")));
        }

        self::assertCount(10, $this->requests);
    }

    public function testAnUnconfiguredWebhookIsNotCalledAtAll(): void
    {
        $this->handler(webhookId: '0', webhookToken: 'unconfigured')->handle($this->record('Boom'));

        self::assertSame([], $this->requests);
    }

    /** @return array{content: string} */
    private function decodedBody(int $index): array
    {
        /** @var array{content: string} $decoded */
        $decoded = json_decode((string) $this->requests[$index]['body'], true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function handler(string $webhookId = '12345', string $webhookToken = 's3cr3t'): DiscordWebhookHandler
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body']];

            return new MockResponse('', ['http_code' => 204]);
        });

        return new DiscordWebhookHandler(
            new DiscordAlertFormatter(new RequestStack(), 'prod', \dirname(__DIR__, 2)),
            $client,
            $webhookId,
            $webhookToken,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(string $message, ?\DateTimeImmutable $at = null, array $context = []): LogRecord
    {
        return new LogRecord($at ?? new \DateTimeImmutable(), 'request', Level::Critical, $message, $context);
    }
}
