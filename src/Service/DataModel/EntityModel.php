<?php

declare(strict_types=1);

namespace App\Service\DataModel;

final readonly class EntityModel
{
    /**
     * @param list<FieldModel>       $fields
     * @param list<AssociationModel> $associations owning side only
     */
    public function __construct(
        public string $name,
        public string $tableName,
        public array $fields,
        public array $associations,
    ) {
    }
}
