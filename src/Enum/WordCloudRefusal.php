<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a word was not taken.
 *
 * Every one of these is decided on the server (App\Service\WordCloud\WordCloudSubmissionPolicy):
 * the student's form hides what it can, and the endpoint refuses regardless - a closed cloud that
 * only hides its form is a cloud anybody can still write into.
 */
enum WordCloudRefusal: string
{
    case Closed = 'closed';
    case Empty = 'empty';
    case TooLong = 'too_long';
    case TooManyWords = 'too_many_words';
    case AlreadyProposed = 'already_proposed';
    case QuotaReached = 'quota_reached';

    public function messageKey(): string
    {
        return match ($this) {
            self::Closed => 'wordCloudRefusalClosedMessage',
            self::Empty => 'wordCloudRefusalEmptyMessage',
            self::TooLong => 'wordCloudRefusalTooLongMessage',
            self::TooManyWords => 'wordCloudRefusalTooManyWordsMessage',
            self::AlreadyProposed => 'wordCloudRefusalAlreadyProposedMessage',
            self::QuotaReached => 'wordCloudRefusalQuotaReachedMessage',
        };
    }
}
