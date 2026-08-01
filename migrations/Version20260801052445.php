<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801052445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the "stage" counterpart of Modality::$isAlternance (Modality::$isTraineeship).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE modality ADD is_traineeship TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE modality DROP is_traineeship');
    }
}
