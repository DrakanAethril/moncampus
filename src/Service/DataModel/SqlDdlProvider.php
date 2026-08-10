<?php

declare(strict_types=1);

namespace App\Service\DataModel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The physical model as executable SQL: the CREATE TABLE statements Doctrine derives from the
 * mappings, i.e. the schema the migrations converge to. Offered as a download next to the
 * diagrams - the MPD in its most literal form.
 */
final class SqlDdlProvider
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function ddl(): string
    {
        $em = $this->doctrine->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('The default manager is not an ORM entity manager.');
        }

        $statements = new SchemaTool($em)->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        return implode(";\n", $statements).";\n";
    }
}
