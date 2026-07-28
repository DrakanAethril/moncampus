<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260728191959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Laptop::$currentConditionType (screen 25b "État initial" at creation, before a laptop has ever been on loan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop ADD current_condition_type_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop
            ADD
              CONSTRAINT FK_E001563BD5EF2156 FOREIGN KEY (current_condition_type_id) REFERENCES laptop_condition_type (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_E001563BD5EF2156 ON laptop (current_condition_type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop DROP FOREIGN KEY FK_E001563BD5EF2156');
        $this->addSql('DROP INDEX IDX_E001563BD5EF2156 ON laptop');
        $this->addSql('ALTER TABLE laptop DROP current_condition_type_id');
    }
}
