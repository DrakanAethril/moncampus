<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724174735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE skill_group ADD teacher_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE skill_group ADD CONSTRAINT FK_48E8D7F941807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_48E8D7F941807E1D ON skill_group (teacher_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE skill_group DROP FOREIGN KEY FK_48E8D7F941807E1D');
        $this->addSql('DROP INDEX IDX_48E8D7F941807E1D ON skill_group');
        $this->addSql('ALTER TABLE skill_group DROP teacher_id');
    }
}
