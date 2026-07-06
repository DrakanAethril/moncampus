<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706212212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lesson_session ADD topic_id INT DEFAULT NULL, CHANGE title title VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE lesson_session ADD CONSTRAINT FK_253887731F55203D FOREIGN KEY (topic_id) REFERENCES topic (id)');
        $this->addSql('CREATE INDEX IDX_253887731F55203D ON lesson_session (topic_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lesson_session DROP FOREIGN KEY FK_253887731F55203D');
        $this->addSql('DROP INDEX IDX_253887731F55203D ON lesson_session');
        $this->addSql('ALTER TABLE lesson_session DROP topic_id, CHANGE title title VARCHAR(255) NOT NULL');
    }
}
