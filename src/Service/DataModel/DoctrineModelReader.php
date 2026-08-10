<?php

declare(strict_types=1);

namespace App\Service\DataModel;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Builds the neutral data model from Doctrine's own metadata, the same source TechnicalProfile
 * counts entities from: nothing here is typed by hand, so the documentation stays true as the
 * schema evolves. Read once per worker, like the rest of the technical page.
 */
final class DoctrineModelReader
{
    private ?DataModel $model = null;

    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function read(): DataModel
    {
        if (null !== $this->model) {
            return $this->model;
        }

        $em = $this->doctrine->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('The default manager is not an ORM entity manager.');
        }
        $platform = $em->getConnection()->getDatabasePlatform();

        $entities = [];
        $joinTables = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            $shortName = $metadata->getReflectionClass()->getShortName();

            $fields = [];
            foreach ($metadata->fieldMappings as $mapping) {
                $enumType = $mapping->enumType;
                $fields[] = new FieldModel(
                    $mapping->fieldName,
                    trim($mapping->columnName, '`'),
                    $mapping->type,
                    strtoupper(Type::getType($mapping->type)->getSQLDeclaration((array) $mapping, $platform)),
                    $mapping->nullable ?? false,
                    $mapping->id ?? false,
                    $mapping->unique ?? false,
                    null === $enumType ? null : self::shortName($enumType),
                );
            }

            $associations = [];
            foreach ($metadata->associationMappings as $association) {
                $target = self::shortName($association->targetEntity);
                if ($association instanceof ToOneOwningSideMapping) {
                    $joinColumn = $association->joinColumns[0] ?? null;
                    $associations[] = new AssociationModel(
                        $association->fieldName,
                        $shortName,
                        $target,
                        $association->isManyToOne() ? AssociationModel::MANY_TO_ONE : AssociationModel::ONE_TO_ONE,
                        $joinColumn?->nullable ?? true,
                        null === $joinColumn ? null : trim($joinColumn->name, '`'),
                        null,
                        $association->id ?? false,
                    );
                } elseif ($association instanceof ManyToManyOwningSideMapping) {
                    $joinTable = $association->joinTable;
                    $name = trim($joinTable->name, '`');
                    $associations[] = new AssociationModel(
                        $association->fieldName,
                        $shortName,
                        $target,
                        AssociationModel::MANY_TO_MANY,
                        false,
                        null,
                        $name,
                    );
                    $joinTables[$name] = new JoinTableModel(
                        $name,
                        $joinTable->joinColumns[0]->name,
                        trim($metadata->getTableName(), '`'),
                        $joinTable->inverseJoinColumns[0]->name,
                        trim($em->getClassMetadata($association->targetEntity)->getTableName(), '`'),
                    );
                }
            }

            $entities[$shortName] = new EntityModel(
                $shortName,
                trim($metadata->getTableName(), '`'),
                $fields,
                $associations,
            );
        }

        ksort($entities);

        return $this->model = new DataModel($entities, array_values($joinTables));
    }

    private static function shortName(string $className): string
    {
        $position = strrpos($className, '\\');

        return false === $position ? $className : substr($className, $position + 1);
    }
}
