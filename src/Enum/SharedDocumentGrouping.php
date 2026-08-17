<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the student's « Documents partagés » list is cut up.
 *
 * Topic first, and it is the default: a student looking for a document knows which matière it
 * belongs to long before they remember who handed it out.
 */
enum SharedDocumentGrouping: string
{
    case Topic = 'topic';
    case Teacher = 'teacher';
    case None = 'none';

    public function labelKey(): string
    {
        return match ($this) {
            self::Topic => 'sharedDocumentGroupTopicLabel',
            self::Teacher => 'sharedDocumentGroupTeacherLabel',
            self::None => 'sharedDocumentGroupNoneLabel',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Topic, self::Teacher, self::None];
    }

    public static function fromRequestValue(string $value): self
    {
        return self::tryFrom($value) ?? self::Topic;
    }
}
