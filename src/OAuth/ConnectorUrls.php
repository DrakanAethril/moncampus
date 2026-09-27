<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The absolute addresses the connector announces about itself.
 *
 * They are compared **character for character** by the client: the protected-resource metadata's
 * `resource` must equal the URL the teacher typed into claude.ai, and the authorization server's
 * `issuer` must equal the prefix its metadata was fetched from (RFC 8414 § 3.3). Building every one
 * of them from the same request context, in one place, is what keeps them equal - behind the
 * TLS-terminating proxy, `TRUSTED_PROXIES` is what makes that context say https.
 */
final readonly class ConnectorUrls
{
    public const string MCP_ROUTE = 'app_mcp';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function mcpUrl(): string
    {
        return $this->urls->generate(self::MCP_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function resourceMetadataUrl(): string
    {
        return $this->urls->generate('app_oauth_protected_resource_metadata', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function issuer(): string
    {
        $context = $this->urls->getContext();
        $scheme = $context->getScheme();
        $port = 'https' === $scheme ? $context->getHttpsPort() : $context->getHttpPort();
        $defaultPort = 'https' === $scheme ? 443 : 80;

        return $scheme.'://'.$context->getHost().($port === $defaultPort ? '' : ':'.$port);
    }

    public function absolute(string $route): string
    {
        return $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
