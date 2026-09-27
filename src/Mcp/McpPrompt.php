<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A ready-made request the teacher picks from claude.ai's menu - « transposer un cours en
 * séquence » - which expands into a message telling Claude which tools to use, in which order.
 */
#[AutoconfigureTag(self::TAG)]
interface McpPrompt
{
    public const string TAG = 'app.mcp_prompt';

    public function name(): string;

    public function title(): string;

    public function description(): string;

    /**
     * @return list<array{name: string, description: string, required: bool}>
     */
    public function arguments(): array;

    /**
     * The text of the user message the prompt expands to.
     *
     * @param array<string, string> $arguments
     */
    public function render(array $arguments): string;
}
