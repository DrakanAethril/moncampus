<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Enum\Feature;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One tool of the Claude connector - one thing Claude may do on the platform in its user's name.
 *
 * A tool is a thin door onto the services the screens already use: it reads its arguments, asks the
 * same voters, calls the same writer, and says what it did. It never carries a rule of its own that
 * a screen does not also apply - the connector is another way in, not another platform.
 *
 * The texts (title, description, schema descriptions) are **French**, like the prompt catalogues:
 * they are read by the model that talks to a French-speaking teacher, and by that teacher in
 * claude.ai's tool list.
 */
#[AutoconfigureTag(self::TAG)]
interface McpTool
{
    public const string TAG = 'app.mcp_tool';

    /** snake_case, stable: it is what a conversation calls, and renaming it breaks the ones under way. */
    public function name(): string;

    public function title(): string;

    public function description(): string;

    /**
     * The JSON Schema of the arguments (`type: object`).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Whether the tool only reads. claude.ai asks before running a tool that writes, unless the
     * teacher has allowed it for good - which is why this must be true only when it is true.
     */
    public function isReadOnly(): bool;

    /**
     * The features that must all be lit for the user; the tool is not even listed otherwise.
     *
     * @return list<Feature>
     */
    public function features(): array;

    /**
     * @throws McpToolException for anything the model should read and correct - a document that does
     *                          not parse, an id that is not the teacher's
     */
    public function call(McpToolCall $call): McpToolResult;
}
