<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use AsyncAws\Core\Exception\Http\HttpException;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * A reminder a batch could not send, and whether trying again makes sense.
 *
 * Passing: SES throttling the account, SES or the network not answering - the line is tried again
 * at the next pass, and the pass stops, insisting would only worsen a limit. Definitive: SES
 * refusing the mail itself (MessageRejected, an address it will not take), or nobody to write to -
 * the line fails at once, with the reason.
 */
final class ReminderDeliveryException extends \RuntimeException
{
    /** SES codes that mean « not now » rather than « not this mail ». */
    private const array PASSING_CODES = ['Throttling', 'TooManyRequestsException', 'LimitExceededException', 'ServiceUnavailable', 'InternalFailure', 'SendingPausedException'];

    private function __construct(string $message, public readonly bool $passing, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function noAddress(): self
    {
        return new self('Aucune adresse de contact.', false);
    }

    public static function invalidAddress(\Throwable $previous): self
    {
        return new self('Adresse refusée : '.$previous->getMessage(), false, $previous);
    }

    public static function fromTransport(TransportExceptionInterface $exception): self
    {
        $status = null;
        if ($exception instanceof HttpTransportException) {
            try {
                $status = $exception->getResponse()->getStatusCode();
            } catch (\Throwable) {
                $status = null;
            }
        }

        $previous = $exception->getPrevious();
        $code = $previous instanceof HttpException ? $previous->getAwsCode() : null;

        return new self($exception->getMessage(), self::isPassing($status, $code), $exception);
    }

    public static function isPassing(?int $statusCode, ?string $awsCode): bool
    {
        if (null !== $awsCode && \in_array($awsCode, self::PASSING_CODES, true)) {
            return true;
        }

        return null === $statusCode || 429 === $statusCode || $statusCode >= 500;
    }
}
