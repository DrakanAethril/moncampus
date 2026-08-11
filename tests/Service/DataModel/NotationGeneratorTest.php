<?php

declare(strict_types=1);

namespace App\Tests\Service\DataModel;

use App\Service\DataModel\AssociationModel;
use App\Service\DataModel\DataModel;
use App\Service\DataModel\EntityModel;
use App\Service\DataModel\FieldModel;
use App\Service\DataModel\JoinTableModel;
use App\Service\DataModel\NotationGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The generator is pure over the neutral model, so a three-entity model built by hand is enough to
 * pin every notation: a mandatory many-to-one, a nullable self-reference, a many-to-many with its
 * join table, and an entity outside the requested subset (drawn as external).
 */
class NotationGeneratorTest extends TestCase
{
    private NotationGenerator $generator;
    private DataModel $model;

    protected function setUp(): void
    {
        $this->generator = new NotationGenerator();

        $id = new FieldModel('id', 'id', 'integer', 'INT', false, true, false);

        $this->model = new DataModel(
            [
                'Program' => new EntityModel('Program', 'program', [
                    $id,
                    new FieldModel('name', 'name', 'string', 'VARCHAR(255)', false, false, false),
                ], [
                    new AssociationModel('cohort', 'Program', 'Cohort', AssociationModel::MANY_TO_ONE, false, 'cohort_id'),
                    new AssociationModel('students', 'Program', 'User', AssociationModel::MANY_TO_MANY, false, null, 'program_user'),
                ]),
                'Cohort' => new EntityModel('Cohort', 'cohort', [
                    $id,
                    new FieldModel('label', 'label', 'string', 'VARCHAR(100)', false, false, false),
                ], [
                    new AssociationModel('parent', 'Cohort', 'Cohort', AssociationModel::MANY_TO_ONE, true, 'parent_id'),
                ]),
                'User' => new EntityModel('User', 'user', [
                    $id,
                    new FieldModel('username', 'username', 'string', 'VARCHAR(180)', false, false, true),
                ], []),
            ],
            [
                new JoinTableModel('program_user', 'program_id', 'program', 'user_id', 'user'),
            ],
        );
    }

    public function testMcdDrawsMeriseAssociationsWithCardinalities(): void
    {
        $mcd = $this->generator->mcd($this->model, ['Program', 'Cohort']);

        self::assertStringContainsString('flowchart', $mcd);
        // Entities carry their conceptual attributes, never a foreign key column.
        self::assertStringContainsString('Program[', $mcd);
        self::assertStringContainsString('name', $mcd);
        self::assertStringNotContainsString('cohort_id', $mcd);
        // Mandatory many-to-one: 1,1 on the owning side, 0,n on the target side.
        self::assertStringContainsString('"1,1"', $mcd);
        self::assertStringContainsString('"0,n"', $mcd);
        // Nullable self-reference: 0,1 on the owning side.
        self::assertStringContainsString('"0,1"', $mcd);
        // User is outside the subset: drawn as an external ghost, without attributes.
        self::assertStringContainsString('User', $mcd);
        self::assertStringNotContainsString('username', $mcd);
    }

    public function testMldListsTablesWithForeignKeys(): void
    {
        $tables = $this->generator->mld($this->model, ['Program', 'Cohort', 'User']);

        $byName = array_combine(array_column($tables, 'name'), $tables);
        self::assertArrayHasKey('program', $byName);
        self::assertArrayHasKey('program_user', $byName);

        $programColumns = array_combine(
            array_column($byName['program']['columns'], 'name'),
            $byName['program']['columns'],
        );
        self::assertTrue($programColumns['id']['primary']);
        self::assertFalse($programColumns['id']['foreign']);
        self::assertTrue($programColumns['cohort_id']['foreign']);
        self::assertSame('cohort', $programColumns['cohort_id']['references']);

        // A join table is nothing but its two foreign keys, both part of the primary key.
        foreach ($byName['program_user']['columns'] as $column) {
            self::assertTrue($column['primary']);
            self::assertTrue($column['foreign']);
        }
    }

    public function testMldTextUsesRelationalNotation(): void
    {
        $text = $this->generator->mldText($this->model, ['Program', 'Cohort', 'User']);

        self::assertStringContainsString('program (_id_, name, #cohort_id)', $text);
        self::assertStringContainsString('program_user (_#program_id_, _#user_id_)', $text);
    }

    public function testMpdDescribesPhysicalTables(): void
    {
        $mpd = $this->generator->mpd($this->model, ['Program', 'Cohort', 'User']);

        self::assertStringContainsString('erDiagram', $mpd);
        self::assertStringContainsString('INT id PK', $mpd);
        self::assertStringContainsString('VARCHAR(255) name', $mpd);
        self::assertStringContainsString('INT cohort_id FK', $mpd);
        // Mandatory relation: exactly-one on the referenced side; nullable one: zero-or-one.
        self::assertStringContainsString('cohort ||--o{ program', $mpd);
        self::assertStringContainsString('cohort |o--o{ cohort', $mpd);
        // The join table is a real table at this level, linked to both sides.
        self::assertStringContainsString('program ||--o{ program_user', $mpd);
        self::assertStringContainsString('user ||--o{ program_user', $mpd);
    }

    public function testUmlDrawsClassesWithMultiplicities(): void
    {
        $uml = $this->generator->uml($this->model, ['Program', 'Cohort']);

        self::assertStringContainsString('classDiagram', $uml);
        self::assertStringContainsString('class Program', $uml);
        self::assertStringContainsString('+int id', $uml);
        self::assertStringContainsString('+string name', $uml);
        self::assertStringContainsString('Program "0..*" --> "1" Cohort : cohort', $uml);
        self::assertStringContainsString('Cohort "0..*" --> "0..1" Cohort : parent', $uml);
        // Many-to-many keeps no direction and no join table at this level.
        self::assertStringContainsString('Program "0..*" -- "0..*" User : students', $uml);
        self::assertStringNotContainsString('program_user', $uml);
        // External class: named, empty, stereotyped.
        self::assertStringContainsString('<<externe>>', $uml);
    }

    public function testMocodoWritesOneLinePerEntityAndAssociation(): void
    {
        $mocodo = $this->generator->mocodo($this->model, ['Program', 'Cohort', 'User']);

        self::assertStringContainsString('Program: id, name', $mocodo);
        self::assertStringContainsString('COHORT, 11 Program, 0N Cohort', $mocodo);
        self::assertStringContainsString('PARENT, 01 Cohort, 0N Cohort', $mocodo);
        self::assertStringContainsString('STUDENTS, 0N Program, 0N User', $mocodo);
    }

    public function testPlantUmlIsSelfContained(): void
    {
        $uml = $this->generator->plantUml($this->model, ['Program', 'Cohort', 'User']);

        self::assertStringStartsWith('@startuml', $uml);
        self::assertStringContainsString('class Program {', $uml);
        self::assertStringContainsString('+id : int', $uml);
        self::assertStringContainsString('Program "0..*" --> "1" Cohort : cohort', $uml);
        self::assertStringContainsString('@enduml', trim($uml));
    }
}
