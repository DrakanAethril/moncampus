<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713132836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the internal messaging tables: message_thread (+ manual-recipient join), message, message_attachment, message_thread_recipient - see design/validated/internal-messaging.md.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, sent_at DATETIME NOT NULL, thread_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_B6BD307FE2904019 (thread_id), INDEX IDX_B6BD307FF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_attachment (id INT AUTO_INCREMENT NOT NULL, storage_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, message_id INT NOT NULL, INDEX IDX_B68FF524537A1329 (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_thread (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, audience_type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, last_message_at DATETIME NOT NULL, sender_id INT NOT NULL, program_id INT DEFAULT NULL, in_reply_to_thread_id INT DEFAULT NULL, INDEX IDX_607D18CF624B39D (sender_id), INDEX IDX_607D18C3EB8070A (program_id), INDEX IDX_607D18CE25339CD (in_reply_to_thread_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_thread_manual_recipient (message_thread_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_37993FA48829462F (message_thread_id), INDEX IDX_37993FA4A76ED395 (user_id), PRIMARY KEY (message_thread_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_thread_recipient (id INT AUTO_INCREMENT NOT NULL, last_read_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, thread_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_B3C8DA82E2904019 (thread_id), INDEX IDX_B3C8DA82A76ED395 (user_id), UNIQUE INDEX uniq_message_thread_recipient (thread_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FE2904019 FOREIGN KEY (thread_id) REFERENCES message_thread (id)');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE message_attachment ADD CONSTRAINT FK_B68FF524537A1329 FOREIGN KEY (message_id) REFERENCES message (id)');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT FK_607D18CF624B39D FOREIGN KEY (sender_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT FK_607D18C3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT FK_607D18CE25339CD FOREIGN KEY (in_reply_to_thread_id) REFERENCES message_thread (id)');
        $this->addSql('ALTER TABLE message_thread_manual_recipient ADD CONSTRAINT FK_37993FA48829462F FOREIGN KEY (message_thread_id) REFERENCES message_thread (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_thread_manual_recipient ADD CONSTRAINT FK_37993FA4A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_thread_recipient ADD CONSTRAINT FK_B3C8DA82E2904019 FOREIGN KEY (thread_id) REFERENCES message_thread (id)');
        $this->addSql('ALTER TABLE message_thread_recipient ADD CONSTRAINT FK_B3C8DA82A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FE2904019');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FF675F31B');
        $this->addSql('ALTER TABLE message_attachment DROP FOREIGN KEY FK_B68FF524537A1329');
        $this->addSql('ALTER TABLE message_thread DROP FOREIGN KEY FK_607D18CF624B39D');
        $this->addSql('ALTER TABLE message_thread DROP FOREIGN KEY FK_607D18C3EB8070A');
        $this->addSql('ALTER TABLE message_thread DROP FOREIGN KEY FK_607D18CE25339CD');
        $this->addSql('ALTER TABLE message_thread_manual_recipient DROP FOREIGN KEY FK_37993FA48829462F');
        $this->addSql('ALTER TABLE message_thread_manual_recipient DROP FOREIGN KEY FK_37993FA4A76ED395');
        $this->addSql('ALTER TABLE message_thread_recipient DROP FOREIGN KEY FK_B3C8DA82E2904019');
        $this->addSql('ALTER TABLE message_thread_recipient DROP FOREIGN KEY FK_B3C8DA82A76ED395');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE message_attachment');
        $this->addSql('DROP TABLE message_thread');
        $this->addSql('DROP TABLE message_thread_manual_recipient');
        $this->addSql('DROP TABLE message_thread_recipient');
    }
}
