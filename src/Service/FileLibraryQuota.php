<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\FileLibraryNodeRepository;

/**
 * What a library is allowed to weigh, and what it weighs (design/validated/file-library.md,
 * "Quota").
 *
 * Two rules that look like implementation detail and are the design:
 *
 * - **the usage is measured, never stored** - see FileLibraryNodeRepository::usedBytes();
 * - **the limit is nullable on User, and null is not zero.** It means "whatever the platform
 *   currently says", so raising the default later raises it for everyone who was never overridden.
 *   Writing 1 073 741 824 into 1 500 rows would freeze today's default into history.
 *
 * Enforcement is **server-side and late, on purpose**. PHP has received the whole body before any
 * controller runs, so a "pre-flight" check cannot save the bandwidth: the picker does a courtesy
 * check in the browser against the size it already knows, this refuses before the object is written,
 * and the refusal carries the numbers - « Il reste 240 Mo dans votre bibliothèque, ce fichier en
 * pèse 380. »
 *
 * An admin lowering a quota below current usage deletes nothing: the bar reads 118 %, in red, and
 * uploads are refused until the teacher frees space. Any other behaviour would mean deleting a
 * teacher's files as a side effect of an administrative edit.
 */
class FileLibraryQuota
{
    /** Amber from here, red from 90 % - the bar's three states (mockup 1). */
    public const int AMBER_PERCENT = 75;
    public const int RED_PERCENT = 90;

    public function __construct(
        private readonly FileLibraryNodeRepository $nodes,
        private readonly string $fileLibraryDefaultQuota,
    ) {
    }

    /** The platform default, read from FILE_LIBRARY_DEFAULT_QUOTA - 1 Go unless a deployment says otherwise. */
    public function defaultBytes(): int
    {
        return ByteSize::parse($this->fileLibraryDefaultQuota) ?? 1024 ** 3;
    }

    public function limitFor(User $owner): int
    {
        return $owner->getFileLibraryQuotaBytes() ?? $this->defaultBytes();
    }

    public function usedBytes(User $owner): int
    {
        return $this->nodes->usedBytes($owner);
    }

    public function remainingBytes(User $owner): int
    {
        return max(0, $this->limitFor($owner) - $this->usedBytes($owner));
    }

    /**
     * The percentage the bar draws. **Not capped at 100**: a quota lowered below current usage reads
     * 118 %, in red, and saying "100 %" there would hide the thing the teacher has to act on.
     */
    public function usedPercent(User $owner): int
    {
        $limit = $this->limitFor($owner);

        return $limit <= 0 ? 0 : (int) round($this->usedBytes($owner) / $limit * 100);
    }

    /** green / amber / red - the modifier the bar carries. */
    public function level(User $owner): string
    {
        $percent = $this->usedPercent($owner);

        return match (true) {
            $percent >= self::RED_PERCENT => 'red',
            $percent >= self::AMBER_PERCENT => 'amber',
            default => 'green',
        };
    }

    public function accepts(User $owner, int $incomingBytes): bool
    {
        return $incomingBytes <= $this->remainingBytes($owner);
    }

    /**
     * The refusal, with its numbers - the message the picker shows on the row that failed.
     *
     * @return array{key: string, parameters: array<string, string>}
     */
    public function refusal(User $owner, int $incomingBytes): array
    {
        return [
            'key' => 'fileLibraryQuotaExceededMessage',
            'parameters' => [
                '%remaining%' => ByteSize::format($this->remainingBytes($owner)),
                '%size%' => ByteSize::format($incomingBytes),
            ],
        ];
    }
}
