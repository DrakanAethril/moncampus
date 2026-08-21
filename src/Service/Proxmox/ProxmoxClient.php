<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Enum\ProxmoxCredentialKind;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
 * A cached ticket can stop being honoured before it expires - Proxmox reboots, its authentication
 * key is rotated, the clocks drift apart - and the cache has no way of knowing. Without the retry
 * in request(), the host then answers 401 to everything for the rest of the 100 minutes, on
 * credentials that are perfectly correct. So a 401 on a ticket that *came from the cache* drops it
 * and replays the call once on a freshly minted one; a 401 on a fresh ticket is a real refusal and
 * is reported as such.
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
     * Whether the ticket in hand was read from the shared cache rather than obtained just now. It
     * is the whole condition of the retry: replaying a call on a ticket that was minted seconds
     * ago would only ask Proxmox to refuse the same credentials twice.
     */
    private bool $sessionFromCache = false;

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
    private function request(string $method, string $path, array $payload, bool $isRetry = false): ProxmoxResponse
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
            $phrase = $this->statusPhrase($response);
        } catch (HttpClientExceptionInterface $exception) {
            // The developer's message names the verb and the path; the administrator's names the
            // address and the port, because "is 8006 open from this server?" is the question they
            // can actually act on. See ProxmoxUnavailableException for why there are two.
            throw ProxmoxUnavailableException::unreachable($this->baseUrl, \sprintf('%s %s failed: %s', $method, $path, $exception->getMessage()), $exception);
        }

        if (401 === $status) {
            // Once, and only from the cache: $isRetry closes the recursion even if the flag were
            // ever wrong, and requestTicket() clears the flag on the way through anyway.
            if (!$isRetry && ProxmoxCredentialKind::Password === $this->credentials->kind && $this->sessionFromCache) {
                $this->forgetSession();

                return $this->request($method, $path, $payload, true);
            }

            throw ProxmoxUnavailableException::authenticationRefused(\sprintf('%s %s answered HTTP 401%s', $method, $path, $this->explain($content)), $this->refusalKey(), $this->refusalReason($status, $phrase, $content));
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
            $this->sessionFromCache = true;

            return $this->session = ['ticket' => $cached['ticket'], 'csrf' => $cached['csrf']];
        }

        $session = $this->requestTicket();
        $this->sessionFromCache = false;

        $item->set($session)->expiresAfter(self::TICKET_TTL_SECONDS);
        $this->ticketCache->save($item);

        return $this->session = $session;
    }

    /**
     * Throws the ticket away, here and in the shared cache, so the next call logs in again. The
     * cache entry goes too: keeping it would leave the next request - and every other worker -
     * reading the very ticket that was just refused.
     */
    private function forgetSession(): void
    {
        $this->session = null;
        $this->sessionFromCache = false;
        $this->ticketCache->deleteItem($this->cacheKey);
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
            $phrase = $this->statusPhrase($response);
        } catch (HttpClientExceptionInterface $exception) {
            throw ProxmoxUnavailableException::unreachable($this->baseUrl, \sprintf('Could not obtain a ticket: %s', $exception->getMessage()), $exception);
        }

        if (401 === $status) {
            // The username, the realm or the password is wrong. Which of the three, only Proxmox
            // knows, and it says so in the status line rather than in the body - see
            // statusPhrase(). Naming all three is what is left when it says nothing.
            throw ProxmoxUnavailableException::authenticationRefused(\sprintf('POST /access/ticket answered HTTP 401%s', $this->explain($content)), 'proxmoxAuthenticationRefusedPasswordError', $this->refusalReason($status, $phrase, $content));
        }

        if ($status >= 400) {
            throw new ProxmoxUnavailableException(\sprintf('POST /access/ticket answered HTTP %d%s', $status, $this->explain($content)));
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
     * Which of the two "we refused you" sentences to show. They differ by what there is to check:
     * a token has an id and a secret of its own on top of the account and the realm.
     */
    private function refusalKey(): string
    {
        return ProxmoxCredentialKind::ApiToken === $this->credentials->kind
            ? 'proxmoxAuthenticationRefusedTokenError'
            : 'proxmoxAuthenticationRefusedPasswordError';
    }

    /**
     * What Proxmox actually said about a refusal, as `401 invalid token value!` - the half that
     * turns a bare 401 into something to act on.
     *
     * Both places are read because the two ends of the wire use different ones: pveproxy puts its
     * reason in the **status line** and answers a body of `{"data":null}`, while the mock of the
     * development stack - and Proxmox itself on some errors - carries a `message` in the body. The
     * body wins when there is one, being the more specific of the two; the status line is the
     * fallback, and the bare number is what is left when neither says anything.
     */
    private function refusalReason(int $status, ?string $phrase, string $content): string
    {
        $detail = $this->said($content);

        if ('' === $detail && null !== $phrase) {
            $detail = $phrase;
        }

        return '' === $detail ? (string) $status : \sprintf('%d %s', $status, $detail);
    }

    /**
     * The reason phrase of the status line, `invalid token value!` in
     * `HTTP/1.1 401 invalid token value!`.
     *
     * There is no getter for it: Symfony's ResponseInterface exposes the status code and the
     * headers, and the raw status line survives only in the `response_headers` info, first entry.
     * ext-curl is what runs here (see App\Service\Proxmox\ProxmoxTlsOptions), and CurlHttpClient
     * keeps it there.
     */
    private function statusPhrase(ResponseInterface $response): ?string
    {
        $headers = $response->getInfo('response_headers');

        if (!\is_array($headers)) {
            return null;
        }

        $statusLine = $headers[0] ?? null;

        if (!\is_string($statusLine) || 1 !== preg_match('#^HTTP/\S+\s+\d{3}\s+(\S.*)$#', trim($statusLine), $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * Proxmox puts the useful half of an error in the body, as `{"errors": {...}}` or a plain
     * `{"data": null, "message": "..."}`. Kept short: it lands in a flash message and in the
     * operations log.
     */
    private function said(string $content): string
    {
        $decoded = json_decode($content, true);

        if (!\is_array($decoded)) {
            return '';
        }

        $message = $decoded['message'] ?? null;
        if (\is_string($message) && '' !== $message) {
            return trim($message);
        }

        $errors = $decoded['errors'] ?? null;
        if (\is_array($errors) && [] !== $errors) {
            $parts = [];
            foreach ($errors as $field => $reason) {
                $parts[] = \sprintf('%s %s', (string) $field, \is_scalar($reason) ? (string) $reason : '');
            }

            return trim(implode(', ', $parts));
        }

        return '';
    }

    /** The same thing, punctuated for the middle of a developer's sentence. */
    private function explain(string $content): string
    {
        $said = $this->said($content);

        return '' === $said ? '' : ': '.$said;
    }
}
