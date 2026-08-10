<?php

declare(strict_types=1);

namespace App\Service\DataModel;

/**
 * One scalar field of an entity, with both its conceptual face (property, Doctrine type) and its
 * physical face (column, SQL type). Association columns are not fields: they live on
 * AssociationModel, which is what lets the conceptual notations ignore them.
 */
final readonly class FieldModel
{
    public function __construct(
        public string $name,
        public string $columnName,
        public string $type,
        public string $sqlType,
        public bool $nullable,
        public bool $primary,
        public bool $unique,
        public ?string $enumType = null,
    ) {
    }
}
