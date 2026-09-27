<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Enum\PlatformActivityType;
use App\Service\JsonRequestPayload;
use App\Service\PlatformActivityRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Model Context Protocol, the part of it this server speaks: JSON-RPC 2.0 over plain HTTP POST,
 * one message per request, a JSON answer, **no session and no stream**.
 *
 * Stateless on purpose. The protocol allows a server to hand out an `Mcp-Session-Id` and to hold an
 * SSE stream open; both would pin one of the eight FrankenPHP workers of production for as long as a
 * conversation lasts, and neither buys anything to a server that only answers tool calls. Every
 * request carries its own OAuth token, so every request is complete on its own.
 *
 * Methods: `initialize`, `ping`, `tools/list`, `tools/call`, `prompts/list`, `prompts/get`, and the
 * client's notifications, which need no answer.
 */
final class McpServer
{
    /** Newest first: the first one the client also speaks is the one answered. */
    public const array PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public const int PARSE_ERROR = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS = -32602;
    public const int INTERNAL_ERROR = -32603;

    /** @var list<McpPrompt> */
    private readonly array $prompts;

    /**
     * @param iterable<McpPrompt> $prompts
     */
    public function __construct(
        private readonly McpToolRegistry $tools,
        #[AutowireIterator(McpPrompt::TAG)] iterable $prompts,
        private readonly PlatformActivityRecorder $activity,
        private readonly LoggerInterface $logger,
    ) {
        $list = [];
        foreach ($prompts as $prompt) {
            $list[] = $prompt;
        }
        $this->prompts = $list;
    }

    /**
     * @param array<array-key, mixed> $message one decoded JSON-RPC message
     *
     * @return array<string, mixed>|null the response, or null for a notification
     */
    public function handle(array $message, User $user, ?OAuthGrant $grant, Request $request): ?array
    {
        $payload = JsonRequestPayload::fromArray($message);
        $id = $message['id'] ?? null;
        $method = $payload->string('method');

        if ('2.0' !== $payload->string('jsonrpc') || '' === $method) {
            return self::error(\is_int($id) || \is_string($id) ? $id : null, self::INVALID_REQUEST, 'Invalid JSON-RPC 2.0 request.');
        }

        // A notification has no id and gets no answer - `notifications/initialized` above all.
        if (!\array_key_exists('id', $message)) {
            return null;
        }

        if (!\is_int($id) && !\is_string($id)) {
            return self::error(null, self::INVALID_REQUEST, 'The request id must be a string or an integer.');
        }

        $params = $payload->object('params');

        return match ($method) {
            'initialize' => self::result($id, $this->initialize($params)),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => array_map($this->describeTool(...), $this->tools->availableTo($user))]),
            'tools/call' => $this->callTool($id, $params, $user, $grant, $request),
            'prompts/list' => self::result($id, ['prompts' => array_map($this->describePrompt(...), $this->prompts)]),
            'prompts/get' => $this->getPrompt($id, $params),
            default => self::error($id, self::METHOD_NOT_FOUND, \sprintf('Method not found: %s', $method)),
        };
    }

    /**
     * @return array{jsonrpc: string, id: int|string|null, error: array{code: int, message: string}}
     */
    public static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param array<string, mixed>|\stdClass $result
     *
     * @return array{jsonrpc: string, id: int|string, result: array<string, mixed>|\stdClass}
     */
    private static function result(int|string $id, array|\stdClass $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function initialize(JsonRequestPayload $params): array
    {
        $asked = $params->string('protocolVersion');

        return [
            'protocolVersion' => \in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'moncampus',
                'title' => 'MonCampus',
                'version' => '1.0.0',
            ],
            'instructions' => McpInstructions::TEXT,
        ];
    }

    /** @return array<string, mixed> */
    private function describeTool(McpTool $tool): array
    {
        return [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => [
                'title' => $tool->title(),
                'readOnlyHint' => $tool->isReadOnly(),
                // Nothing in this connector deletes or overwrites what a teacher wrote: the
                // writing tools create, append, or fill a barème the screen would let them fill.
                'destructiveHint' => false,
                'idempotentHint' => $tool->isReadOnly(),
                'openWorldHint' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function describePrompt(McpPrompt $prompt): array
    {
        return [
            'name' => $prompt->name(),
            'title' => $prompt->title(),
            'description' => $prompt->description(),
            'arguments' => $prompt->arguments(),
        ];
    }

    /** @return array<string, mixed> */
    private function callTool(int|string $id, JsonRequestPayload $params, User $user, ?OAuthGrant $grant, Request $request): array
    {
        $name = $params->string('name');
        $tool = $this->tools->find($name, $user);

        if (null === $tool) {
            return self::error($id, self::INVALID_PARAMS, \sprintf('Unknown tool: %s', $name));
        }

        try {
            $result = $tool->call(new McpToolCall($user, $grant, $params->object('arguments')));
        } catch (McpToolException $exception) {
            $result = McpToolResult::error($exception);
        } catch (\Throwable $exception) {
            // The model is told something went wrong, never what: an exception message can carry a
            // query, a path or somebody else's data. The log keeps the whole of it.
            $this->logger->error('Claude connector tool failed', ['tool' => $name, 'exception' => $exception]);
            $result = McpToolResult::error(new McpToolException('L\'opération n\'a pas abouti à cause d\'une erreur interne de MonCampus. Vérifiez dans MonCampus ce qui a été enregistré avant de réessayer.'));
        }

        if (null !== $result->created) {
            $this->activity->record(PlatformActivityType::ClaudeConnectorContentCreated, $user, $request, [
                'tool' => $name,
                'kind' => $result->created['kind'],
                'id' => (string) $result->created['id'],
                'client' => $grant?->getClient()->getClientName() ?? '',
            ]);
        }

        return self::result($id, $result->toArray());
    }

    /** @return array<string, mixed> */
    private function getPrompt(int|string $id, JsonRequestPayload $params): array
    {
        $name = $params->string('name');

        foreach ($this->prompts as $prompt) {
            if ($prompt->name() !== $name) {
                continue;
            }

            $arguments = [];
            foreach ($params->object('arguments')->toArray() as $key => $value) {
                if (\is_string($key) && \is_scalar($value)) {
                    $arguments[$key] = trim((string) $value);
                }
            }

            return self::result($id, [
                'description' => $prompt->description(),
                'messages' => [[
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => $prompt->render($arguments)],
                ]],
            ]);
        }

        return self::error($id, self::INVALID_PARAMS, \sprintf('Unknown prompt: %s', $name));
    }
}
