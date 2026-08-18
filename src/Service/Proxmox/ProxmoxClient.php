<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Enum\ProxmoxCredentialKind;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One Proxmox VE host, one credential set, one client. Built by
 * App\Service\Proxmox\ProxmoxClientFactory, never autowired directly: it needs a decrypted secret
 * and a materialised TLS policy, and both are that factory's business.
 *
 * The two authentication modes behave quite differently, and the difference is the whole reason the
 * class is not just a wrapper around request():
 *
 *   - An **API token** is stateless. The secret goes into `Authorization: PVEAPIToken=…` on every
 *     call, there is no session and no CSRF token, and nothing is cached.
 *   - A **password** is traded at `/access/ticket` for a two-hour ticket plus a
 *     `CSRFPreventionToken`. The ticket rides in a cookie, and the CSRF token is *mandatory on
 *     every non-GET* - a POST without it answers 401 with a message that does not say so. The
 *     ticket is cached for 100 minutes under a key that includes a digest of the secret, so
 *     changing the password invalidates the cache by itself.
 *
 * Every failure - transport, TLS, 401/403, a body that is not the `{"data": …}` envelope - comes
 * out as App\Service\Proxmox\ProxmoxUnavailableException, so callers have one thing to catch.
 */
class ProxmoxClient
{
    /** Proxmox tickets last two hours; 100 minutes leaves room for a slow call to finish on one. */
    private const int TICKET_TTL_SECONDS = 6000;

    /** @var array{ticket: string, csrf: string}|null */
    private ?array $session = null;

    /**
     * @param array<string, mixed> $tlsOptions built by App\Service\Proxmox\ProxmoxTlsOptions
     * @param string               $cacheKey   identifies host + credential set, never the secret
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $ticketCache,
        private readonly string $baseUrl,
        private readonly ProxmoxCredentials $credentials,
        private readonly array $tlsOptions,
        private readonly string $cacheKey,
    ) {
    }

    /** @param array<string, scalar> $query */
    public function get(string $path, array $query = []): ProxmoxResponse
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /** @param array<string, scalar> $body */
    public function post(string $path, array $body = []): ProxmoxResponse
    {
        return $this->request('POST', $path, ['body' => $body]);
    }

    /** @param array<string, scalar> $body */
    public function put(string $path, array $body = []): ProxmoxResponse
    {
        return $this->request('PUT', $path, ['body' => $body]);
    }

    /**
     * `GET /version` - the cheapest call that proves both the transport and the credentials, which
     * is why it is what "test the connection" runs.
     */
    public function version(): ProxmoxResponse
    {
        return $this->get('/version');
    }

    /**
     * Whether the pool a host declares as its perimeter actually exists. Checked at connection
     * time, because a typo here makes every later action refuse silently: the scope guard would
     * find no guest in a pool that is not there and conclude, correctly but uselessly, that
     * nothing is in scope.
     */
    public function poolExists(string $pool): bool
    {
        try {
            $this->get('/pools/'.rawurlencode($pool));

            return true;
        } catch (ProxmoxUnavailableException) {
            return false;
        }
    }

    /**
     * The configuration of many guests at once, keyed by VMID.
     *
     * `/cluster/resources` lists every machine in one call but carries no IP, so learning where the
     * addresses are means one `/config` per guest - twenty-eight round trips on a modest host. In
     * sequence that is a scan nobody runs twice.
     *
     * So every request is fired first and the answers are consumed as they arrive: HttpClient's
     * `request()` returns immediately and only reading a response blocks, and `stream()` hands them
     * back in completion order. The cost becomes the slowest single call rather than their sum.
     *
     * A guest whose configuration cannot be read is skipped rather than fatal - one machine
     * mid-migration must not cost the whole scan.
     *
     * @param list<ProxmoxGuest> $guests
     *
     * @return array<int, array<string, mixed>>
     */
    public function configurations(array $guests): array
    {
        if ([] === $guests) {
            return [];
        }

        $pending = [];
        foreach ($guests as $guest) {
            $url = \sprintf(
                '%s/api2/json/nodes/%s/%s/%d/config',
                $this->baseUrl,
                rawurlencode($guest->node),
                $guest->endpointSegment(),
                $guest->vmid,
            );

            try {
                $options = $this->tlsOptions;
                $options['headers'] = $this->headers('GET');

                // Nothing has been sent yet in any meaningful sense: this only queues the call.
                $pending[$guest->vmid] = $this->httpClient->request('GET', $url, $options);
            } catch (HttpClientExceptionInterface) {
                continue;
            }
        }

        $configurations = [];

        foreach ($this->httpClient->stream($pending) as $response => $chunk) {
            try {
                if (!$chunk->isLast()) {
                    continue;
                }

                if (200 !== $response->getStatusCode()) {
                    continue;
                }

                $vmid = array_search($response, $pending, true);

                if (!\is_int($vmid)) {
                    continue;
                }

                $configurations[$vmid] = ProxmoxResponse::fromJson($response->getContent(false))->row();
            } catch (HttpClientExceptionInterface|ProxmoxUnavailableException) {
                // One unreadable guest, not one failed scan.
                continue;
            }
        }

        return $configurations;
    }

    /**
     * @param array{query?: array<string, scalar>, body?: array<string, scalar>} $payload
     */
    private function request(string $method, string $path, array $payload): ProxmoxResponse
    {
        $options = $this->tlsOptions;
        $options['headers'] = $this->headers($method);

        if ([] !== ($payload['query'] ?? [])) {
            $options['query'] = $payload['query'];
        }

        if (\array_key_exists('body', $payload)) {
            // Proxmox reads form-encoded parameters, not JSON, on POST and PUT alike.
            $options['body'] = $payload['body'];
        }

        $url = $this->baseUrl.'/api2/json'.$path;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            // false: a 4xx/5xx must reach the mapping below with its body, not blow up inside
            // getContent() with a message that hides what Proxmox actually said.
            $content = $response->getContent(false);
            $status = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            throw new ProxmoxUnavailableException(\sprintf('%s %s failed: %s', $method, $path, $exception->getMessage()), previous: $exception);
        }

        if ($status >= 400) {
            throw new ProxmoxUnavailableException(\sprintf('%s %s answered HTTP %d%s', $method, $path, $status, $this->explain($content)));
        }

        return ProxmoxResponse::fromJson($content);
    }

    /** @return array<string, string> */
    private function headers(string $method): array
    {
        if (ProxmoxCredentialKind::ApiToken === $this->credentials->kind) {
            return ['Authorization' => $this->credentials->authorizationHeader()];
        }

        $session = $this->session();
        $headers = ['Cookie' => 'PVEAuthCookie='.$session['ticket']];

        if ('GET' !== $method) {
            $headers['CSRFPreventionToken'] = $session['csrf'];
        }

        return $headers;
    }

    /** @return array{ticket: string, csrf: string} */
    private function session(): array
    {
        if (null !== $this->session) {
            return $this->session;
        }

        $item = $this->ticketCache->getItem($this->cacheKey);
        $cached = $item->get();

        if (\is_array($cached) && \is_string($cached['ticket'] ?? null) && \is_string($cached['csrf'] ?? null)) {
            return $this->session = ['ticket' => $cached['ticket'], 'csrf' => $cached['csrf']];
        }

        $session = $this->requestTicket();

        $item->set($session)->expiresAfter(self::TICKET_TTL_SECONDS);
        $this->ticketCache->save($item);

        return $this->session = $session;
    }

    /** @return array{ticket: string, csrf: string} */
    private function requestTicket(): array
    {
        $options = $this->tlsOptions;
        $options['body'] = [
            'username' => $this->credentials->userId(),
            'password' => $this->credentials->secret,
        ];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.'/api2/json/access/ticket', $options);
            $content = $response->getContent(false);
            $status = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            throw new ProxmoxUnavailableException(\sprintf('Could not obtain a ticket: %s', $exception->getMessage()), previous: $exception);
        }

        if ($status >= 400) {
            // 401 here means the username, the realm or the password is wrong - the three are
            // indistinguishable from the outside, and saying so is more useful than repeating
            // Proxmox's own terse "authentication failure".
            throw new ProxmoxUnavailableException(\sprintf('Authentication refused (HTTP %d): check the username, the realm and the password.', $status));
        }

        $data = ProxmoxResponse::fromJson($content);
        $ticket = $data->nullableString('ticket');
        $csrf = $data->nullableString('CSRFPreventionToken');

        if (null === $ticket || null === $csrf) {
            throw new ProxmoxUnavailableException('The ticket response carried no ticket or no CSRF token.');
        }

        return ['ticket' => $ticket, 'csrf' => $csrf];
    }

    /**
     * Proxmox puts the useful half of an error in the body, as `{"errors": {...}}` or a plain
     * `{"data": null, "message": "..."}`. Kept short: it lands in a flash message and in the
     * operations log.
     */
    private function explain(string $content): string
    {
        $decoded = json_decode($content, true);

        if (!\is_array($decoded)) {
            return '';
        }

        $message = $decoded['message'] ?? null;
        if (\is_string($message) && '' !== $message) {
            return ': '.trim($message);
        }

        $errors = $decoded['errors'] ?? null;
        if (\is_array($errors) && [] !== $errors) {
            $parts = [];
            foreach ($errors as $field => $reason) {
                $parts[] = \sprintf('%s %s', (string) $field, \is_scalar($reason) ? (string) $reason : '');
            }

            return ': '.trim(implode(', ', $parts));
        }

        return '';
    }
}
