<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260714205127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agenda_event (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, audience_type VARCHAR(20) NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_6B965E393EB8070A (program_id), INDEX IDX_6B965E39B03A8386 (created_by_id), INDEX IDX_6B965E39F5A2E305 (inactivated_by_id), INDEX IDX_6B965E39E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE agenda_event_manual_recipient (agenda_event_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_1A6772E770AF5DEF (agenda_event_id), INDEX IDX_1A6772E7A76ED395 (user_id), PRIMARY KEY (agenda_event_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE announcement (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, audience_type VARCHAR(20) NOT NULL, creation_date DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_4DB9D91C3EB8070A (program_id), INDEX IDX_4DB9D91CB03A8386 (created_by_id), INDEX IDX_4DB9D91CF5A2E305 (inactivated_by_id), INDEX IDX_4DB9D91CE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE announcement_manual_recipient (announcement_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_8885777D913AEA17 (announcement_id), INDEX IDX_8885777DA76ED395 (user_id), PRIMARY KEY (announcement_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE agenda_event ADD CONSTRAINT FK_6B965E393EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE agenda_event ADD CONSTRAINT FK_6B965E39B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE agenda_event ADD CONSTRAINT FK_6B965E39F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE agenda_event ADD CONSTRAINT FK_6B965E39E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE agenda_event_manual_recipient ADD CONSTRAINT FK_1A6772E770AF5DEF FOREIGN KEY (agenda_event_id) REFERENCES agenda_event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agenda_event_manual_recipient ADD CONSTRAINT FK_1A6772E7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE announcement_manual_recipient ADD CONSTRAINT FK_8885777D913AEA17 FOREIGN KEY (announcement_id) REFERENCES announcement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE announcement_manual_recipient ADD CONSTRAINT FK_8885777DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agenda_event DROP FOREIGN KEY FK_6B965E393EB8070A');
        $this->addSql('ALTER TABLE agenda_event DROP FOREIGN KEY FK_6B965E39B03A8386');
        $this->addSql('ALTER TABLE agenda_event DROP FOREIGN KEY FK_6B965E39F5A2E305');
        $this->addSql('ALTER TABLE agenda_event DROP FOREIGN KEY FK_6B965E39E562D849');
        $this->addSql('ALTER TABLE agenda_event_manual_recipient DROP FOREIGN KEY FK_1A6772E770AF5DEF');
        $this->addSql('ALTER TABLE agenda_event_manual_recipient DROP FOREIGN KEY FK_1A6772E7A76ED395');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91C3EB8070A');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91CB03A8386');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91CF5A2E305');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91CE562D849');
        $this->addSql('ALTER TABLE announcement_manual_recipient DROP FOREIGN KEY FK_8885777D913AEA17');
        $this->addSql('ALTER TABLE announcement_manual_recipient DROP FOREIGN KEY FK_8885777DA76ED395');
        $this->addSql('DROP TABLE agenda_event');
        $this->addSql('DROP TABLE agenda_event_manual_recipient');
        $this->addSql('DROP TABLE announcement');
        $this->addSql('DROP TABLE announcement_manual_recipient');
    }
}
