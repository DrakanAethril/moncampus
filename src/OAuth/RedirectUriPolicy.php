<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * Where a code of the Claude connector may be sent - the half of a public client's identity that
 * PKCE does not cover.
 *
 * A closed list rather than « any https URL »: registration is open to the whole internet, so an
 * open list would let anybody register a client that sends codes to their own server, and dress its
 * consent screen as Claude. What is accepted:
 *
 * - **claude.ai's callback**, and the claude.com one Anthropic has announced it may move to - both
 *   serve the web, desktop and mobile apps;
 * - **the loopback interface on any port and any path** - Claude Code and the MCP Inspector listen on a
 *   port picked at run time (RFC 8252 § 7.3). Nothing outside the user's own machine can receive
 *   what is sent there.
 */
final class RedirectUriPolicy
{
    /** @var list<string> */
    public const array HOSTED_CALLBACKS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    public function isRegistrable(string $uri): bool
    {
        return \in_array($uri, self::HOSTED_CALLBACKS, true) || null !== $this->loopbackParts($uri);
    }

    /**
     * Whether `$presented` is one of the client's registered URIs - exactly, or a loopback URI that
     * differs from a registered one by its port only.
     *
     * @param list<string> $registered
     */
    public function matches(array $registered, string $presented): bool
    {
        if (\in_array($presented, $registered, true)) {
            return true;
        }

        $presentedParts = $this->loopbackParts($presented);
        if (null === $presentedParts) {
            return false;
        }

        foreach ($registered as $uri) {
            if ($this->loopbackParts($uri) === $presentedParts) {
                return true;
            }
        }

        return false;
    }

    /**
     * The loopback URI without its port, or null when it is not one.
     *
     * @return array{host: string, path: string, query: string}|null
     */
    private function loopbackParts(string $uri): ?array
    {
        $parts = parse_url($uri);

        if (!\is_array($parts)
            || 'http' !== ($parts['scheme'] ?? null)
            || !\in_array(strtolower($parts['host'] ?? ''), self::LOOPBACK_HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        return [
            'host' => strtolower($parts['host'] ?? ''),
            'path' => $parts['path'] ?? '/',
            'query' => $parts['query'] ?? '',
        ];
    }
}
