<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\OAuth\Pkce;
use App\OAuth\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A connection of the Claude connector without walking the consent screen - which
 * ClaudeConnectorOAuthFlowTest pins on its own - and a way to call a tool with it.
 */
trait ClaudeConnectorTestTrait
{
    private function accessTokenFor(User $user): string
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $client = new OAuthClient('client-'.bin2hex(random_bytes(4)), 'Claude', ['https://claude.ai/api/mcp/auth_callback'], null);
        $grant = new OAuthGrant($user, $client, 'library offline_access', new \DateTimeImmutable());
        $entityManager->persist($client);
        $entityManager->persist($grant);

        $issuer = static::getContainer()->get(TokenIssuer::class);
        $verifier = str_repeat('v', 43);
        $code = $issuer->issueCode($grant, 'https://claude.ai/api/mcp/auth_callback', Pkce::challengeOf($verifier), null);

        return $issuer->exchangeCode($client, $code, 'https://claude.ai/api/mcp/auth_callback', $verifier, null)->accessToken;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{isError: bool, text: string, data: array<string, mixed>}
     */
    private function callTool(string $accessToken, string $tool, array $arguments = []): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ], content: (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('result', $response, (string) json_encode($response));
        $result = $response['result'];
        self::assertIsArray($result);
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        $first = $content[0] ?? null;
        self::assertIsArray($first);
        $data = $result['structuredContent'] ?? [];
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        $text = $first['text'] ?? '';
        self::assertIsString($text);

        return ['isError' => true === ($result['isError'] ?? null), 'text' => $text, 'data' => $data];
    }

    /**
     * @return list<string>
     */
    private function toolNames(string $accessToken): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        $result = $response['result'] ?? null;
        self::assertIsArray($result);
        $tools = $result['tools'] ?? null;
        self::assertIsArray($tools);

        $names = [];
        foreach ($tools as $tool) {
            if (\is_array($tool) && \is_string($tool['name'] ?? null)) {
                $names[] = $tool['name'];
            }
        }

        return $names;
    }
}
