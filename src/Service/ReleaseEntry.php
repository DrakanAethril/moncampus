<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ReleaseEntryType;

/**
 * One line of a release note: what changed, in one sentence a member of staff can read.
 *
 * $detail is the second sentence some lines need and most do not - what it means in practice, or
 * where to find it. Deliberately plain text, not HTML: the changelog is written in a YAML file by
 * hand, and a format nobody can get wrong is worth more here than rich text.
 */
final readonly class ReleaseEntry
{
    public function __construct(
        public ReleaseEntryType $type,
        public string $title,
        public ?string $detail = null,
    ) {
    }
}
