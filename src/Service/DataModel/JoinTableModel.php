<?php

declare(strict_types=1);

namespace App\Service\DataModel;

/**
 * The table behind a many-to-many association. It exists only at the logical and physical levels:
 * the conceptual and object notations draw the association itself instead.
 */
final readonly class JoinTableModel
{
    public function __construct(
        public string $name,
        public string $sourceColumn,
        public string $sourceTable,
        public string $targetColumn,
        public string $targetTable,
    ) {
    }
}
