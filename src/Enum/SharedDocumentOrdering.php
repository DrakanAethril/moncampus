<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the rows are ordered - inside each group when the list is grouped, and over the whole list
 * when it is flat. The two cases share one enum because they are one question the reader asks once.
 *
 * Date is the default and it runs **descending**: the newest document is the one being looked for.
 * Alphabetical runs ascending, on the file's name.
 */
enum SharedDocumentOrdering: string
{
    case Date = 'date';
    case Name = 'name';

    public function labelKey(): string
    {
        return match ($this) {
            self::Date => 'sharedDocumentOrderDateLabel',
            self::Name => 'sharedDocumentOrderNameLabel',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Date, self::Name];
    }

    public static function fromRequestValue(string $value): self
    {
        return self::tryFrom($value) ?? self::Date;
    }
}
