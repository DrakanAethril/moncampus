<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * A refusal the model should read and act on - returned to it as a tool result with `isError`, not
 * as a protocol error, so that Claude corrects its document or its id and tries again (MCP
 * specification, « Error Handling »). The message is French and addressed to the teacher as much as
 * to the model: claude.ai often shows it as it is.
 */
final class McpToolException extends \RuntimeException
{
    /**
     * @param list<string> $details one line per problem - the rejected questions of a quiz, the
     *                              invalid rows of a barème
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
