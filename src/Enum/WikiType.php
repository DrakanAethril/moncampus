<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What kind of workspace an App\Entity\Wiki is (design/validated/wiki.md, "Data model").
 *
 * The two cases are not two flavours of the same thing: a Personal wiki has an owner and refuses
 * members and programs at the entity level, so sharing always means creating a Shared one. That
 * refusal is what makes "a personal wiki cannot be shared" a property of the model rather than a
 * convention somebody has to remember.
 */
enum WikiType: string
{
    case Personal = 'personal';
    case Shared = 'shared';

    public function labelKey(): string
    {
        return match ($this) {
            self::Personal => 'wikiTypePersonalLabel',
            self::Shared => 'wikiTypeSharedLabel',
        };
    }
}
