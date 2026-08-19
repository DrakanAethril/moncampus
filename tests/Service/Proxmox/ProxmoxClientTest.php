<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxmox;

use App\Enum\ProxmoxCredentialKind;
use App\Service\Proxmox\ProxmoxClient;
use App\Service\Proxmox\ProxmoxCredentials;
use App\Service\Proxmox\ProxmoxUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The client, against MockHttpClient - there is no Proxmox host reachable from this container and
 * there does not need to be: what is worth pinning here is the protocol, not the hypervisor.
 *
 * The three things that break silently against a real host, and that only a recorded request can
 * show:
 *   - the CSRF header goes out on a POST and stays home on a GET (Proxmox answers 401 without it,
 *     with a message that never says "CSRF");
 *   - the API token is formatted `PVEAPIToken=user@realm!name=secret` and no ticket is fetched;
 *   - a ticket is asked for once and then reused, so N calls are not N logins.
 */
class ProxmoxClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    private function client(
        ProxmoxCredentials $credentials,
        MockResponse|array $responses,
        ?ArrayAdapter $cache = null,
    ): ProxmoxClient {
        $queue = \is_array($responses) ? $responses : [$responses];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($queue) ?? new MockResponse('{"data":null}');
        });

        return new ProxmoxClient(
            $http,
            $cache ?? new ArrayAdapter(),
            'https://pve.example.lan:8006',
            $credentials,
            [],
            'test-key',
        );
    }

    private function token(): ProxmoxCredentials
    {
        return new ProxmoxCredentials(ProxmoxCredentialKind::ApiToken, 'svc-moncampus', 'pve', 'moncampus', 'a-secret');
    }

    private function password(): ProxmoxCredentials
    {
        return new ProxmoxCredentials(ProxmoxCredentialKind::Password, 'svc-moncampus', 'pve', null, 'a-password');
    }

    /**
     * Through a method rather than an inline `$this->calls = []`, so the assertion that follows
     * still reasons about the property's declared type instead of an empty array literal.
     */
    private function forgetCalls(): void
    {
        $this->calls = [];
    }

    /** @return list<string> */
    private function headersOf(int $index): array
    {
        $headers = $this->calls[$index]['options']['headers'] ?? [];

        return array_values(array_map(strval(...), \is_array($headers) ? $headers : []));
    }

    public function testAnApiTokenTravelsInTheAuthorizationHeaderAndFetchesNoTicket(): void
    {
        $client = $this->client($this->token(), new MockResponse('{"data":{"version":"8.3.2"}}'));

        self::assertSame('8.3.2', $client->version()->string('version'));
        self::assertCount(1, $this->calls, 'a token needs no /access/ticket call');
        self::assertContains(
            'Authorization: PVEAPIToken=svc-moncampus@pve!moncampus=a-secret',
            $this->headersOf(0),
        );
    }

    public function testTheUrlIsBuiltUnderApi2Json(): void
    {
        $client = $this->client($this->token(), new MockResponse('{"data":[]}'));
        $client->get('/nodes');

        self::assertSame('https://pve.example.lan:8006/api2/json/nodes', $this->calls[0]['url']);
    }

    public function testAPasswordIsTradedForATicketAndTheCsrfHeaderRidesOnlyOnWrites(): void
    {
        $client = $this->client($this->password(), [
            new MockResponse('{"data":{"ticket":"PVE:ticket","CSRFPreventionToken":"CSRF:token"}}'),
            new MockResponse('{"data":[]}'),
            new MockResponse('{"data":"UPID:pve1:0000:qmstart::"}'),
        ]);

        $client->get('/nodes');
        $client->post('/nodes/pve1/qemu/204/status/start');

        self::assertCount(3, $this->calls);
        self::assertStringEndsWith('/access/ticket', $this->calls[0]['url']);

        self::assertContains('Cookie: PVEAuthCookie=PVE:ticket', $this->headersOf(1));
        self::assertNotContains('CSRFPreventionToken: CSRF:token', $this->headersOf(1), 'a GET must not carry the CSRF header');

        self::assertContains('Cookie: PVEAuthCookie=PVE:ticket', $this->headersOf(2));
        self::assertContains('CSRFPreventionToken: CSRF:token', $this->headersOf(2), 'every non-GET must carry it');
    }

    public function testTheTicketIsFetchedOnceAndReusedFromTheCache(): void
    {
        $cache = new ArrayAdapter();

        $first = $this->client($this->password(), [
            new MockResponse('{"data":{"ticket":"PVE:ticket","CSRFPreventionToken":"CSRF:token"}}'),
            new MockResponse('{"data":[]}'),
        ], $cache);
        $first->get('/nodes');

        $this->forgetCalls();

        // A second client for the same host - a new request, a new instance, the same ticket.
        $second = $this->client($this->password(), [new MockResponse('{"data":[]}')], $cache);
        $second->get('/nodes');

        self::assertCount(1, $this->calls, 'the cached ticket must spare the second login');
        self::assertContains('Cookie: PVEAuthCookie=PVE:ticket', $this->headersOf(0));
    }

    public function testARefusedTicketBecomesAProxmoxUnavailableException(): void
    {
        $client = $this->client($this->password(), new MockResponse('', ['http_code' => 401]));

        $this->expectException(ProxmoxUnavailableException::class);
        $this->expectExceptionMessageMatches('/username, the realm and the password/');
        $client->version();
    }

    public function testA401OnAnOrdinaryCallBecomesAProxmoxUnavailableException(): void
    {
        $client = $this->client($this->token(), new MockResponse('{"data":null}', ['http_code' => 401]));

        $this->expectException(ProxmoxUnavailableException::class);
        $client->version();
    }

    public function testProxmoxOwnWordingIsCarriedIntoTheMessage(): void
    {
        $client = $this->client($this->token(), new MockResponse(
            '{"data":null,"errors":{"vmid":"value does not look like a valid VM ID"}}',
            ['http_code' => 400],
        ));

        $this->expectException(ProxmoxUnavailableException::class);
        $this->expectExceptionMessageMatches('/valid VM ID/');
        $client->post('/nodes/pve1/qemu');
    }

    public function testATransportFailureCarriesBothMessages(): void
    {
        // `error` is how MockHttpClient reproduces an unreachable host - which is the ordinary
        // state of a hypervisor that is off, not an edge case. A blocked port looks like this: no
        // HTTP status, no body, just curl giving up.
        //
        // The developer needs the verb and the path; the administrator needs the address and
        // something to go and check. Sending the first to the screen is what production did on
        // 2026-08-19, and "Idle timeout reached for https://…" tells nobody to look at a firewall.
        $client = $this->client($this->token(), new MockResponse('', ['error' => 'Connection timed out']));

        try {
            $client->version();
            self::fail('An unreachable host must not answer.');
        } catch (ProxmoxUnavailableException $exception) {
            self::assertStringContainsString('GET /version failed', $exception->getMessage());
            self::assertSame('proxmoxHostUnreachableError', $exception->userMessageKey());
            self::assertSame(['%address%' => 'https://pve.example.lan:8006'], $exception->userMessageParameters());
        }
    }

    public function testARefusedStatusStaysWithoutAKeyAndKeepsWhatProxmoxSaid(): void
    {
        // The counterpart: an answered 403 is not a reachability problem, and dressing it up as
        // "host unreachable" would send an administrator to check a firewall that is working. No
        // key, so the caller falls back to what the hypervisor itself replied.
        $client = $this->client($this->token(), new MockResponse('{"errors":{"pool":"Permission check failed"}}', ['http_code' => 403]));

        try {
            $client->version();
            self::fail('A 403 must not answer.');
        } catch (ProxmoxUnavailableException $exception) {
            self::assertNull($exception->userMessageKey());
            self::assertStringContainsString('403', $exception->getMessage());
        }
    }

    public function testABodyThatIsNotAProxmoxEnvelopeIsRejected(): void
    {
        // A captive portal or a reverse-proxy error page answers 200 with HTML; treating that as
        // an empty answer would show an administrator an empty machine list instead of a failure.
        $client = $this->client($this->token(), new MockResponse('<html>Gateway</html>'));

        $this->expectException(ProxmoxUnavailableException::class);
        $client->version();
    }

    public function testAMissingPoolIsAnAnswerRatherThanAnException(): void
    {
        $client = $this->client($this->token(), new MockResponse('{"data":null}', ['http_code' => 404]));

        self::assertFalse($client->poolExists('moncampus'));
    }

    public function testAnExistingPoolAnswersTrue(): void
    {
        $client = $this->client($this->token(), new MockResponse('{"data":{"members":[]}}'));

        self::assertTrue($client->poolExists('moncampus'));
    }

    public function testCredentialsNeverPrintTheirSecret(): void
    {
        $dump = print_r($this->token(), true);

        self::assertStringNotContainsString('a-secret', $dump);
        self::assertStringContainsString('***', $dump);
    }
}
