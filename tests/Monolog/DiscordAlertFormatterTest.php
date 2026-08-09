<?php

namespace App\Tests\Monolog;

use App\Monolog\DiscordAlertFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What a production error alert says once it reaches the Discord channel.
 *
 * Two of these are the point of the class rather than cosmetics: the query string must never be
 * quoted (a magic-login token would be readable by everyone in the channel), and the message must
 * stay under Discord's 2000-character hard limit, which rejects the whole post rather than
 * truncating it.
 */
class DiscordAlertFormatterTest extends TestCase
{
    public function testItNamesTheLevelTheChannelAndTheException(): void
    {
        $content = $this->format($this->record(
            'Uncaught PHP Exception RuntimeException: "boom"',
            new \RuntimeException('boom'),
        ));

        self::assertStringContainsString('CRITICAL', $content);
        self::assertStringContainsString('canal request', $content);
        self::assertStringContainsString('Uncaught PHP Exception RuntimeException: "boom"', $content);
        self::assertStringContainsString('Exception : RuntimeException — tests/Monolog/DiscordAlertFormatterTest.php:', $content);
    }

    public function testItReportsTheRequestPathWithoutItsQueryString(): void
    {
        $request = Request::create('https://moncampus.example/login/magic/check?token=secret-magic-token');

        $content = $this->format($this->record('Boom'), new RequestStack(), $request);

        self::assertStringContainsString('Requête : GET https://moncampus.example/login/magic/check', $content);
        self::assertStringNotContainsString('secret-magic-token', $content);
    }

    public function testItStaysUnderDiscordsMessageLimit(): void
    {
        $content = $this->format($this->record(str_repeat('a very long error message. ', 500)));

        self::assertLessThan(2000, mb_strlen($content));
    }

    public function testMessagesSentOutsideProdAreFlaggedAsDev(): void
    {
        self::assertStringStartsWith('[DEV] ', $this->format($this->record('Boom')));
        self::assertStringStartsWith('🚨', $this->format($this->record('Boom'), environment: 'prod'));
    }

    private function format(LogRecord $record, ?RequestStack $requestStack = null, ?Request $request = null, string $environment = 'dev'): string
    {
        $requestStack ??= new RequestStack();
        if (null !== $request) {
            $requestStack->push($request);
        }

        return (new DiscordAlertFormatter($requestStack, $environment, \dirname(__DIR__, 2)))->format($record);
    }

    private function record(string $message, ?\Throwable $exception = null): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'request',
            Level::Critical,
            $message,
            null !== $exception ? ['exception' => $exception] : [],
        );
    }
}
