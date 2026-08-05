<?php

namespace App\Enum;

/**
 * SES scan verdicts on an inbound message, read from the `X-SES-Spam-Verdict` and
 * `X-SES-Virus-Verdict` headers SES adds to the `.eml` dropped on S3 (scanning enabled on the
 * catch-all receipt rules).
 *
 * Nothing is ever rejected on a verdict's word: S3 stays the source of truth and the message is
 * stored no matter what. The verdict only decides on quarantine at display time.
 */
enum EmailScanVerdict: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Gray = 'GRAY';
    case ProcessingFailed = 'PROCESSING_FAILED';

    /** Lenient by design: a missing or unknown header must not fail the worker. */
    public static function fromHeader(?string $value): ?self
    {
        return null !== $value ? self::tryFrom(strtoupper(trim($value))) : null;
    }

    /** Only a FAIL on the virus scan justifies withholding the attachment. */
    public function isDangerous(): bool
    {
        return self::Fail === $this;
    }
}
