<?php

declare(strict_types=1);

namespace App\Service\DataModel;

/**
 * The whole schema as a neutral, framework-free structure. Built once per worker by
 * DoctrineModelReader; every notation generator works from this and never from Doctrine itself,
 * which is what keeps them unit-testable on a hand-built model.
 */
final readonly class DataModel
{
    /**
     * @param array<string, EntityModel> $entities   keyed by short class name
     * @param list<JoinTableModel>       $joinTables
     */
    public function __construct(
        public array $entities,
        public array $joinTables,
    ) {
    }

    public function tableOf(string $entityName): ?string
    {
        return isset($this->entities[$entityName]) ? $this->entities[$entityName]->tableName : null;
    }
}
