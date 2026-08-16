<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One answer from clamd's INSTREAM command, read as one of three outcomes.
 *
 * Kept apart from the socket (App\Service\ClamAvClient) so the interesting half is testable without
 * one, and because the third outcome is where an antivirus goes wrong: an `ERROR` reply is **not**
 * "clean". Anything this class does not recognise is an error too - failing closed on an
 * unparseable answer is the only reading that keeps the scanner worth believing.
 */
final readonly class ClamAvReply
{
    private function __construct(
        public string $raw,
        private ?string $signature,
        private bool $clean,
    ) {
    }

    public static function parse(string $raw): self
    {
        $line = trim($raw, " \t\n\r\0\x0B");

        if (str_ends_with($line, ' OK')) {
            return new self($line, null, true);
        }

        if (str_ends_with($line, ' FOUND')) {
            // "stream: Win.Test.EICAR_HDB-1 FOUND" - the middle is the signature.
            $body = substr($line, 0, -\strlen(' FOUND'));
            $signature = trim(substr($body, (int) strpos($body, ':') + 1));

            return new self($line, '' === $signature ? 'unknown' : $signature, false);
        }

        return new self($line, null, false);
    }

    public function isClean(): bool
    {
        return $this->clean;
    }

    public function signature(): ?string
    {
        return $this->signature;
    }

    public function isError(): bool
    {
        return !$this->clean && null === $this->signature;
    }
}
