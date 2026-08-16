<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the import assistant's second step shows: what the archive holds, what will happen to it,
 * and what will not.
 *
 * A blocking problem refuses the whole file rather than importing what it can - the same posture as
 * the UFA contract import, and for the same reason: half an imported wiki is worse than none,
 * because nobody can tell which half.
 */
final class WikiArchiveAnalysis
{
    public const string KIND_MONCAMPUS = 'moncampus';
    public const string KIND_GENERIC = 'generic';

    /** @var list<array{title: string, path: string, depth: int, attachments: int}> */
    public array $pages = [];

    /** @var list<string> translation keys of what refuses the file outright */
    public array $blocking = [];

    /** @var list<array{message: string, detail: string}> what will be dropped, but does not refuse the file */
    public array $warnings = [];

    public int $attachments = 0;

    public function __construct(public readonly string $kind = self::KIND_GENERIC)
    {
    }

    public function isImportable(): bool
    {
        return [] === $this->blocking && [] !== $this->pages;
    }

    public function block(string $messageKey): void
    {
        if (!\in_array($messageKey, $this->blocking, true)) {
            $this->blocking[] = $messageKey;
        }
    }

    public function warn(string $messageKey, string $detail = ''): void
    {
        $this->warnings[] = ['message' => $messageKey, 'detail' => $detail];
    }
}
