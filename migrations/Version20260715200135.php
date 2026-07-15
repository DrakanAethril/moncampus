<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds sign-up lists ("listes d'inscription"): signup_list (audience-targeted like Announcement/
 * AgendaEvent/MessageThread - see App\Entity\AudienceTargetable), signup_list_attachment,
 * signup_list_registration (the roster), and a nullable signup_list_id FK on agenda_event/
 * announcement/message_thread (a list can optionally attach to one of the three, see
 * App\Entity\AgendaEvent::$signupList's docblock for why the FK lives on that side).
 */
final class Version20260715200135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add signup_list, signup_list_attachment, signup_list_registration, and optional attachment to AgendaEvent/Announcement/MessageThread';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE signup_list (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, registration_deadline DATETIME DEFAULT NULL, public_roster TINYINT NOT NULL, audience_type VARCHAR(20) NOT NULL, include_students TINYINT NOT NULL, include_teachers TINYINT NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_B66B7913B03A8386 (created_by_id), INDEX IDX_B66B7913F5A2E305 (inactivated_by_id), INDEX IDX_B66B7913E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE signup_list_program (signup_list_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_90D537E45CBCA2C0 (signup_list_id), INDEX IDX_90D537E43EB8070A (program_id), PRIMARY KEY (signup_list_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE signup_list_manual_recipient (signup_list_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_4B7910735CBCA2C0 (signup_list_id), INDEX IDX_4B791073A76ED395 (user_id), PRIMARY KEY (signup_list_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE signup_list_attachment (id INT AUTO_INCREMENT NOT NULL, storage_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, signup_list_id INT NOT NULL, INDEX IDX_E9F3E5025CBCA2C0 (signup_list_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE signup_list_registration (id INT AUTO_INCREMENT NOT NULL, registered_at DATETIME NOT NULL, signup_list_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_78C05E7D5CBCA2C0 (signup_list_id), INDEX IDX_78C05E7DA76ED395 (user_id), UNIQUE INDEX signup_list_registration_unique (signup_list_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE signup_list ADD CONSTRAINT FK_B66B7913B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE signup_list ADD CONSTRAINT FK_B66B7913F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE signup_list ADD CONSTRAINT FK_B66B7913E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE signup_list_program ADD CONSTRAINT FK_90D537E45CBCA2C0 FOREIGN KEY (signup_list_id) REFERENCES signup_list (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE signup_list_program ADD CONSTRAINT FK_90D537E43EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE signup_list_manual_recipient ADD CONSTRAINT FK_4B7910735CBCA2C0 FOREIGN KEY (signup_list_id) REFERENCES signup_list (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE signup_list_manual_recipient ADD CONSTRAINT FK_4B791073A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE signup_list_attachment ADD CONSTRAINT FK_E9F3E5025CBCA2C0 FOREIGN KEY (signup_list_id) REFERENCES signup_list (id)');
        $this->addSql('ALTER TABLE signup_list_registration ADD CONSTRAINT FK_78C05E7D5CBCA2C0 FOREIGN KEY (signup_list_id) REFERENCES signup_list (id)');
        $this->addSql('ALTER TABLE signup_list_registration ADD CONSTRAINT FK_78C05E7DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE agenda_event ADD signup_list_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE agenda_event ADD CONSTRAINT FK_6B965E395CBCA2C0 FOREIGN KEY (signup_list_id) REFERENCES signup_list (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6B965E395CBCA2C0 ON agenda_event (signup_list_id)');
        $this->addSql('ALTER TABLE announcement ADD signup_list_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91C5CBCA2C0 FOREIGN KEY (signup_list_id) REFERENCES signup_list (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4DB9D91C5CBCA2C0 ON announcement (signup_list_id)');
        $this->addSql('ALTER TABLE message_thread ADD signup_list_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT FK_607D18C5CBCA2C0 FOREIGN KEY (signup_list_id) REFERENCES signup_list (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_607D18C5CBCA2C0 ON message_thread (signup_list_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup_list DROP FOREIGN KEY FK_B66B7913B03A8386');
        $this->addSql('ALTER TABLE signup_list DROP FOREIGN KEY FK_B66B7913F5A2E305');
        $this->addSql('ALTER TABLE signup_list DROP FOREIGN KEY FK_B66B7913E562D849');
        $this->addSql('ALTER TABLE signup_list_program DROP FOREIGN KEY FK_90D537E45CBCA2C0');
        $this->addSql('ALTER TABLE signup_list_program DROP FOREIGN KEY FK_90D537E43EB8070A');
        $this->addSql('ALTER TABLE signup_list_manual_recipient DROP FOREIGN KEY FK_4B7910735CBCA2C0');
        $this->addSql('ALTER TABLE signup_list_manual_recipient DROP FOREIGN KEY FK_4B791073A76ED395');
        $this->addSql('ALTER TABLE signup_list_attachment DROP FOREIGN KEY FK_E9F3E5025CBCA2C0');
        $this->addSql('ALTER TABLE signup_list_registration DROP FOREIGN KEY FK_78C05E7D5CBCA2C0');
        $this->addSql('ALTER TABLE signup_list_registration DROP FOREIGN KEY FK_78C05E7DA76ED395');
        $this->addSql('DROP TABLE signup_list');
        $this->addSql('DROP TABLE signup_list_program');
        $this->addSql('DROP TABLE signup_list_manual_recipient');
        $this->addSql('DROP TABLE signup_list_attachment');
        $this->addSql('DROP TABLE signup_list_registration');
        $this->addSql('ALTER TABLE agenda_event DROP FOREIGN KEY FK_6B965E395CBCA2C0');
        $this->addSql('DROP INDEX IDX_6B965E395CBCA2C0 ON agenda_event');
        $this->addSql('ALTER TABLE agenda_event DROP signup_list_id');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91C5CBCA2C0');
        $this->addSql('DROP INDEX IDX_4DB9D91C5CBCA2C0 ON announcement');
        $this->addSql('ALTER TABLE announcement DROP signup_list_id');
        $this->addSql('ALTER TABLE message_thread DROP FOREIGN KEY FK_607D18C5CBCA2C0');
        $this->addSql('DROP INDEX IDX_607D18C5CBCA2C0 ON message_thread');
        $this->addSql('ALTER TABLE message_thread DROP signup_list_id');
    }
}
