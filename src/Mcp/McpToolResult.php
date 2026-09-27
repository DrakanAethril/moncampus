<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * What a tool answers: content blocks for the model, and optionally the same data as
 * `structuredContent` - always doubled by a text block, since not every client reads the structured
 * half.
 */
final readonly class McpToolResult
{
    /**
     * @param list<array<string, mixed>> $content    MCP content blocks (text, image)
     * @param array<string, mixed>|null  $structured
     * @param array{kind: string, id: int}|null $created what was created, for the activity log
     */
    public function __construct(
        public array $content,
        public ?array $structured = null,
        public bool $isError = false,
        public ?array $created = null,
    ) {
    }

    /**
     * @param array<string, mixed>              $data
     * @param array{kind: string, id: int}|null $created
     */
    public static function data(string $summary, array $data, ?array $created = null): self
    {
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        return new self([
            ['type' => 'text', 'text' => $summary."\n\n".$json],
        ], $data, false, $created);
    }

    public static function text(string $text): self
    {
        return new self([['type' => 'text', 'text' => $text]]);
    }

    public static function error(McpToolException $exception): self
    {
        $text = $exception->getMessage();
        if ([] !== $exception->details) {
            $text .= "\n- ".implode("\n- ", $exception->details);
        }

        return new self([['type' => 'text', 'text' => $text]], null, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = ['content' => $this->content, 'isError' => $this->isError];

        if (null !== $this->structured) {
            $result['structuredContent'] = $this->structured;
        }

        return $result;
    }
}
