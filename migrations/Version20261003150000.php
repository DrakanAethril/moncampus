<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cours en ligne : seuil de réussite du quiz de test (80 % par défaut)';
    }

    public function up(Schema $schema): void
    {
        // The DEFAULT only fills the existing rows: the entity's own default is what a new row reads.
        $this->addSql('ALTER TABLE online_course ADD test_pass_percent INT DEFAULT 80 NOT NULL');
        $this->addSql('ALTER TABLE online_course ALTER test_pass_percent DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_course DROP test_pass_percent');
    }
}
