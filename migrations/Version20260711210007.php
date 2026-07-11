<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711210007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lesson_log (id INT AUTO_INCREMENT NOT NULL, contenu_realise LONGTEXT DEFAULT NULL, travail_avant_description LONGTEXT DEFAULT NULL, travail_apres_description LONGTEXT DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, lesson_session_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_FD4ACE266C36A50E (lesson_session_id), INDEX IDX_FD4ACE26B03A8386 (created_by_id), INDEX IDX_FD4ACE26F5A2E305 (inactivated_by_id), INDEX IDX_FD4ACE26E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lesson_log_attachment (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, lesson_log_id INT NOT NULL, INDEX IDX_3D99DA4799F1ADD1 (lesson_log_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lesson_log ADD CONSTRAINT FK_FD4ACE266C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id)');
        $this->addSql('ALTER TABLE lesson_log ADD CONSTRAINT FK_FD4ACE26B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE lesson_log ADD CONSTRAINT FK_FD4ACE26F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE lesson_log ADD CONSTRAINT FK_FD4ACE26E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE lesson_log_attachment ADD CONSTRAINT FK_3D99DA4799F1ADD1 FOREIGN KEY (lesson_log_id) REFERENCES lesson_log (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lesson_log DROP FOREIGN KEY FK_FD4ACE266C36A50E');
        $this->addSql('ALTER TABLE lesson_log DROP FOREIGN KEY FK_FD4ACE26B03A8386');
        $this->addSql('ALTER TABLE lesson_log DROP FOREIGN KEY FK_FD4ACE26F5A2E305');
        $this->addSql('ALTER TABLE lesson_log DROP FOREIGN KEY FK_FD4ACE26E562D849');
        $this->addSql('ALTER TABLE lesson_log_attachment DROP FOREIGN KEY FK_3D99DA4799F1ADD1');
        $this->addSql('DROP TABLE lesson_log');
        $this->addSql('DROP TABLE lesson_log_attachment');
    }
}
