<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Service\JsonRequestPayload;

/**
 * One call of a tool: who, through which connection, with what.
 */
final readonly class McpToolCall
{
    public function __construct(
        public User $user,
        public ?OAuthGrant $grant,
        public JsonRequestPayload $arguments,
    ) {
    }

    /**
     * A required id argument, or a readable refusal.
     *
     * @throws McpToolException
     */
    public function requiredId(string $key): int
    {
        $id = $this->arguments->int($key);

        if (null === $id || $id <= 0) {
            throw new McpToolException(\sprintf('L\'argument « %s » est obligatoire (identifiant numérique).', $key));
        }

        return $id;
    }

    public function optionalId(string $key): ?int
    {
        $id = $this->arguments->int($key);

        return null === $id || $id <= 0 ? null : $id;
    }

    /**
     * A required text argument, trimmed, or a readable refusal.
     *
     * @throws McpToolException
     */
    public function requiredString(string $key): string
    {
        $value = trim($this->arguments->string($key));

        if ('' === $value) {
            throw new McpToolException(\sprintf('L\'argument « %s » est obligatoire.', $key));
        }

        return $value;
    }

    /**
     * An argument that holds a whole document (an import format), handed over as an object or as a
     * JSON string - models produce both - and returned as JSON text for the importers, which read
     * text.
     *
     * @throws McpToolException
     */
    public function documentJson(string $key): string
    {
        $value = $this->arguments->toArray()[$key] ?? null;

        if (\is_string($value) && '' !== trim($value)) {
            return $value;
        }

        if (\is_array($value) && [] !== $value) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        }

        throw new McpToolException(\sprintf('L\'argument « %s » est obligatoire : le document au format attendu (voir l\'outil format_guide).', $key));
    }
}
