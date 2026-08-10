<?php

declare(strict_types=1);

namespace App\Service\DataModel;

/**
 * One association, seen from its owning side only - the inverse side is the same fact and would
 * make every relation appear twice. Entity names are short class names.
 */
final readonly class AssociationModel
{
    public const string MANY_TO_ONE = 'manyToOne';
    public const string ONE_TO_ONE = 'oneToOne';
    public const string MANY_TO_MANY = 'manyToMany';

    public function __construct(
        public string $property,
        public string $source,
        public string $target,
        public string $type,
        public bool $nullable,
        public ?string $joinColumn = null,
        public ?string $joinTable = null,
        public bool $primary = false,
    ) {
    }
}
