<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260813131015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz instance deactivation: hides a launched quiz from the class without deleting its attempts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_instance ADD deactivated_at DATETIME DEFAULT NULL, ADD deactivated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_instance ADD CONSTRAINT FK_94F4489BBDC76BAA FOREIGN KEY (deactivated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_94F4489BBDC76BAA ON quiz_instance (deactivated_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_instance DROP FOREIGN KEY FK_94F4489BBDC76BAA');
        $this->addSql('DROP INDEX IDX_94F4489BBDC76BAA ON quiz_instance');
        $this->addSql('ALTER TABLE quiz_instance DROP deactivated_at, DROP deactivated_by_id');
    }
}
