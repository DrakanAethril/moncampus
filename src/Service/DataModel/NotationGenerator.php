<?php

declare(strict_types=1);

namespace App\Service\DataModel;

/**
 * Writes the four classic notations of a data model - MCD (Merise conceptual), MLD (relational
 * schema), MPD (physical tables) and UML class diagram - from the neutral model, for a subset of
 * entities at a time (one functional domain, or everything for the exports).
 *
 * The diagram notations target Mermaid (rendered in the browser); the export notations target the
 * tools students actually use: Mocodo for the MCD, PlantUML for the class diagram, plain text for
 * the relational schema. An association whose target falls outside the requested subset is still
 * drawn, with its target as a bare "external" box - hiding it would misrepresent the model.
 *
 * @phpstan-type MldColumn array{name: string, primary: bool, foreign: bool, references: string|null}
 * @phpstan-type MldTable array{name: string, columns: list<MldColumn>}
 */
final class NotationGenerator
{
    /**
     * Merise conceptual model: entities with their conceptual attributes (identifier underlined,
     * never a foreign key), associations as named nodes carrying the cardinalities.
     *
     * @param list<string> $names
     */
    public function mcd(DataModel $model, array $names): string
    {
        $subset = array_flip($names);
        $lines = ['flowchart LR', '    classDef external stroke-dasharray: 6 4;'];

        foreach ($this->entitiesOf($model, $names) as $entity) {
            $attributes = [];
            foreach ($entity->fields as $field) {
                $attributes[] = $field->primary ? '<u>'.$field->name.'</u>' : $field->name;
            }
            $label = '<b>'.$entity->name.'</b>'.([] === $attributes ? '' : '<br/>'.implode('<br/>', $attributes));
            $lines[] = sprintf('    %s["%s"]', $entity->name, $label);
        }

        $externals = [];
        $node = 0;
        foreach ($this->entitiesOf($model, $names) as $entity) {
            foreach ($entity->associations as $association) {
                if (!isset($subset[$association->target])) {
                    $externals[$association->target] = true;
                }
                [$sourceCard, $targetCard] = match ($association->type) {
                    AssociationModel::MANY_TO_ONE => [$association->nullable ? '0,1' : '1,1', '0,n'],
                    AssociationModel::ONE_TO_ONE => [$association->nullable ? '0,1' : '1,1', '0,1'],
                    default => ['0,n', '0,n'],
                };
                $id = 'A'.$node++;
                $lines[] = sprintf('    %s(["%s"])', $id, $association->property);
                $lines[] = sprintf('    %s ---|"%s"| %s', $association->source, $sourceCard, $id);
                $lines[] = sprintf('    %s ---|"%s"| %s', $id, $targetCard, $association->target);
            }
        }

        foreach (array_keys($externals) as $name) {
            $lines[] = sprintf('    %s["<b>%s</b>"]:::external', $name, $name);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Relational schema: one row per table, foreign keys as columns again (that is the whole point
     * of the logical level), join tables included.
     *
     * @param list<string> $names
     *
     * @return list<MldTable>
     */
    public function mld(DataModel $model, array $names): array
    {
        $tables = [];
        $joinTableNames = [];

        foreach ($this->entitiesOf($model, $names) as $entity) {
            $columns = [];
            foreach ($entity->fields as $field) {
                $columns[] = ['name' => $field->columnName, 'primary' => $field->primary, 'foreign' => false, 'references' => null];
            }
            foreach ($entity->associations as $association) {
                if (null !== $association->joinColumn) {
                    $columns[] = [
                        'name' => $association->joinColumn,
                        'primary' => $association->primary,
                        'foreign' => true,
                        'references' => $model->tableOf($association->target),
                    ];
                }
                if (null !== $association->joinTable) {
                    $joinTableNames[$association->joinTable] = true;
                }
            }
            $tables[] = ['name' => $entity->tableName, 'columns' => $columns];
        }

        foreach ($model->joinTables as $joinTable) {
            if (!isset($joinTableNames[$joinTable->name])) {
                continue;
            }
            $tables[] = ['name' => $joinTable->name, 'columns' => [
                ['name' => $joinTable->sourceColumn, 'primary' => true, 'foreign' => true, 'references' => $joinTable->sourceTable],
                ['name' => $joinTable->targetColumn, 'primary' => true, 'foreign' => true, 'references' => $joinTable->targetTable],
            ]];
        }

        return $tables;
    }

    /**
     * The relational schema in its usual textual form: primary key between underscores, foreign
     * keys prefixed with #.
     *
     * @param list<string> $names
     */
    public function mldText(DataModel $model, array $names): string
    {
        $lines = [];
        foreach ($this->mld($model, $names) as $table) {
            $columns = array_map(static fn (array $column): string => match (true) {
                $column['primary'] && $column['foreign'] => '_#'.$column['name'].'_',
                $column['primary'] => '_'.$column['name'].'_',
                $column['foreign'] => '#'.$column['name'],
                default => $column['name'],
            }, $table['columns']);
            $lines[] = $table['name'].' ('.implode(', ', $columns).')';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Physical model as a Mermaid entity-relationship diagram: real table and column names, SQL
     * types, PK/FK markers, join tables as first-class tables.
     *
     * @param list<string> $names
     */
    public function mpd(DataModel $model, array $names): string
    {
        $subset = array_flip($names);
        $byTable = [];
        foreach ($model->entities as $entity) {
            $byTable[$entity->tableName] = $entity;
        }

        $lines = ['erDiagram'];
        $externals = [];
        $joinTableNames = [];

        foreach ($this->entitiesOf($model, $names) as $entity) {
            $lines[] = '    '.$entity->tableName.' {';
            foreach ($entity->fields as $field) {
                $marker = $field->primary ? ' PK' : ($field->unique ? ' UK' : '');
                $lines[] = sprintf('        %s %s%s', $this->mermaidType($field->sqlType), $field->columnName, $marker);
            }
            foreach ($entity->associations as $association) {
                if (null !== $association->joinColumn) {
                    $type = $this->primaryKeySqlType($model->entities[$association->target] ?? null);
                    $lines[] = sprintf('        %s %s FK', $type, $association->joinColumn);
                }
                if (null !== $association->joinTable) {
                    $joinTableNames[$association->joinTable] = true;
                }
                if (!isset($subset[$association->target])) {
                    $externals[$association->target] = true;
                }
            }
            $lines[] = '    }';
        }

        foreach (array_keys($externals) as $name) {
            $external = $model->entities[$name] ?? null;
            if (null === $external) {
                continue;
            }
            $lines[] = '    '.$external->tableName.' {';
            $lines[] = sprintf('        %s id PK', $this->primaryKeySqlType($external));
            $lines[] = '    }';
        }

        foreach ($this->entitiesOf($model, $names) as $entity) {
            foreach ($entity->associations as $association) {
                if (null === $association->joinColumn) {
                    continue;
                }
                $targetTable = $model->tableOf($association->target) ?? $association->target;
                $lines[] = sprintf(
                    '    %s %s--%s %s : "%s"',
                    $targetTable,
                    $association->nullable ? '|o' : '||',
                    AssociationModel::ONE_TO_ONE === $association->type ? 'o|' : 'o{',
                    $entity->tableName,
                    $association->joinColumn,
                );
            }
        }

        foreach ($model->joinTables as $joinTable) {
            if (!isset($joinTableNames[$joinTable->name])) {
                continue;
            }
            $lines[] = '    '.$joinTable->name.' {';
            $lines[] = sprintf('        %s %s FK', $this->primaryKeySqlType($byTable[$joinTable->sourceTable] ?? null), $joinTable->sourceColumn);
            $lines[] = sprintf('        %s %s FK', $this->primaryKeySqlType($byTable[$joinTable->targetTable] ?? null), $joinTable->targetColumn);
            $lines[] = '    }';
            $lines[] = sprintf('    %s ||--o{ %s : ""', $joinTable->sourceTable, $joinTable->name);
            $lines[] = sprintf('    %s ||--o{ %s : ""', $joinTable->targetTable, $joinTable->name);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * UML class diagram (Mermaid): PHP-side types, association arrows with multiplicities,
     * many-to-many drawn as the undirected association it is at object level.
     *
     * @param list<string> $names
     */
    public function uml(DataModel $model, array $names): string
    {
        $subset = array_flip($names);
        $lines = ['classDiagram', '    direction LR'];
        $externals = [];

        foreach ($this->entitiesOf($model, $names) as $entity) {
            $lines[] = '    class '.$entity->name.' {';
            foreach ($entity->fields as $field) {
                $lines[] = sprintf('        +%s %s', $this->phpType($field), $field->name);
            }
            $lines[] = '    }';
            foreach ($entity->associations as $association) {
                if (!isset($subset[$association->target])) {
                    $externals[$association->target] = true;
                }
            }
        }

        foreach (array_keys($externals) as $name) {
            $lines[] = '    class '.$name.' {';
            $lines[] = '        <<externe>>';
            $lines[] = '    }';
        }

        foreach ($this->entitiesOf($model, $names) as $entity) {
            foreach ($entity->associations as $association) {
                $lines[] = $this->umlEdge($association);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The full conceptual model in Mocodo's textual syntax - the layout is Mocodo's job, this file
     * only states the facts.
     *
     * @param list<string> $names
     */
    public function mocodo(DataModel $model, array $names): string
    {
        $subset = array_flip($names);
        $lines = [];
        foreach ($this->entitiesOf($model, $names) as $entity) {
            $lines[] = $entity->name.': '.implode(', ', array_map(static fn (FieldModel $f): string => $f->name, $entity->fields));
        }

        $used = [];
        foreach ($this->entitiesOf($model, $names) as $entity) {
            foreach ($entity->associations as $association) {
                if (!isset($subset[$association->target])) {
                    continue;
                }
                $name = strtoupper($association->property);
                if (isset($used[$name])) {
                    $name .= '_'.++$used[$name];
                } else {
                    $used[$name] = 1;
                }
                $lines[] = match ($association->type) {
                    AssociationModel::MANY_TO_ONE => sprintf('%s, %s %s, 0N %s', $name, $association->nullable ? '01' : '11', $association->source, $association->target),
                    AssociationModel::ONE_TO_ONE => sprintf('%s, %s %s, 01 %s', $name, $association->nullable ? '01' : '11', $association->source, $association->target),
                    default => sprintf('%s, 0N %s, 0N %s', $name, $association->source, $association->target),
                };
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The full class diagram in PlantUML syntax, ready for any PlantUML renderer.
     *
     * @param list<string> $names
     */
    public function plantUml(DataModel $model, array $names): string
    {
        $subset = array_flip($names);
        $lines = ['@startuml', 'skinparam classAttributeIconSize 0', 'hide empty members'];

        foreach ($this->entitiesOf($model, $names) as $entity) {
            $lines[] = 'class '.$entity->name.' {';
            foreach ($entity->fields as $field) {
                $lines[] = sprintf('  +%s : %s', $field->name, $this->phpType($field));
            }
            $lines[] = '}';
        }

        foreach ($this->entitiesOf($model, $names) as $entity) {
            foreach ($entity->associations as $association) {
                if (!isset($subset[$association->target])) {
                    continue;
                }
                $lines[] = trim($this->umlEdge($association));
            }
        }

        $lines[] = '@enduml';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param list<string> $names
     *
     * @return iterable<EntityModel>
     */
    private function entitiesOf(DataModel $model, array $names): iterable
    {
        foreach ($names as $name) {
            if (isset($model->entities[$name])) {
                yield $model->entities[$name];
            }
        }
    }

    private function umlEdge(AssociationModel $association): string
    {
        return match ($association->type) {
            AssociationModel::MANY_TO_ONE => sprintf('    %s "0..*" --> "%s" %s : %s', $association->source, $association->nullable ? '0..1' : '1', $association->target, $association->property),
            AssociationModel::ONE_TO_ONE => sprintf('    %s "0..1" --> "%s" %s : %s', $association->source, $association->nullable ? '0..1' : '1', $association->target, $association->property),
            default => sprintf('    %s "0..*" -- "0..*" %s : %s', $association->source, $association->target, $association->property),
        };
    }

    private function primaryKeySqlType(?EntityModel $entity): string
    {
        foreach ($entity?->fields ?? [] as $field) {
            if ($field->primary) {
                return $this->mermaidType($field->sqlType);
            }
        }

        return 'INT';
    }

    /**
     * Mermaid's ER grammar accepts neither spaces nor commas inside an attribute type.
     */
    private function mermaidType(string $sqlType): string
    {
        return str_replace([' ', ','], ['', '.'], $sqlType);
    }

    private function phpType(FieldModel $field): string
    {
        if (null !== $field->enumType) {
            return $field->enumType;
        }

        return match ($field->type) {
            'integer', 'smallint', 'bigint' => 'int',
            'boolean' => 'bool',
            'float' => 'float',
            'string', 'text', 'guid', 'decimal', 'ascii_string' => 'string',
            'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable', 'date', 'date_immutable', 'time', 'time_immutable' => 'DateTime',
            'json', 'simple_array' => 'array',
            default => $field->type,
        };
    }
}
