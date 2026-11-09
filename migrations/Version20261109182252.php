<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The UFA's reminder batches (design/validated/ufa-reminder-batches.md): the batch, its formations,
 * its frozen lines, and the batch a logged reminder was sent by.
 */
final class Version20261109182252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UFA: reminder batches';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE internship_reminder_batch (id INT AUTO_INCREMENT NOT NULL, step VARCHAR(30) NOT NULL, period_rank SMALLINT DEFAULT NULL, all_programs TINYINT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, paused_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, school_year_id INT NOT NULL, created_by_id INT NOT NULL, paused_by_id INT DEFAULT NULL, cancelled_by_id INT DEFAULT NULL, INDEX IDX_73E94F46D2EECC3F (school_year_id), INDEX IDX_73E94F46B03A8386 (created_by_id), INDEX IDX_73E94F46616414BD (paused_by_id), INDEX IDX_73E94F46187B2D12 (cancelled_by_id), INDEX idx_internship_reminder_batch_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_reminder_batch_program (internship_reminder_batch_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_342D8856BC613D27 (internship_reminder_batch_id), INDEX IDX_342D88563EB8070A (program_id), PRIMARY KEY (internship_reminder_batch_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_reminder_batch_item (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, exclusion_reason VARCHAR(30) DEFAULT NULL, attempts SMALLINT NOT NULL, claimed_at DATETIME DEFAULT NULL, settled_at DATETIME DEFAULT NULL, recipient_email VARCHAR(180) DEFAULT NULL, copied_secondary_tutor TINYINT NOT NULL, provider_message_id VARCHAR(120) DEFAULT NULL, last_error VARCHAR(255) DEFAULT NULL, batch_id INT NOT NULL, tutor_link_id INT NOT NULL, program_id INT NOT NULL, evaluation_period_id INT DEFAULT NULL, reminder_id INT DEFAULT NULL, INDEX IDX_F4DB719DF39EBE7A (batch_id), INDEX IDX_F4DB719DC55C0FCE (tutor_link_id), INDEX IDX_F4DB719D3EB8070A (program_id), INDEX IDX_F4DB719D3E8BB15A (evaluation_period_id), UNIQUE INDEX UNIQ_F4DB719DD987BE75 (reminder_id), INDEX idx_internship_reminder_batch_item_status (status), UNIQUE INDEX uniq_internship_reminder_batch_item_link (batch_id, tutor_link_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_reminder_batch ADD CONSTRAINT FK_73E94F46D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE internship_reminder_batch ADD CONSTRAINT FK_73E94F46B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_reminder_batch ADD CONSTRAINT FK_73E94F46616414BD FOREIGN KEY (paused_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_reminder_batch ADD CONSTRAINT FK_73E94F46187B2D12 FOREIGN KEY (cancelled_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_reminder_batch_program ADD CONSTRAINT FK_342D8856BC613D27 FOREIGN KEY (internship_reminder_batch_id) REFERENCES internship_reminder_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE internship_reminder_batch_program ADD CONSTRAINT FK_342D88563EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE internship_reminder_batch_item ADD CONSTRAINT FK_F4DB719DF39EBE7A FOREIGN KEY (batch_id) REFERENCES internship_reminder_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE internship_reminder_batch_item ADD CONSTRAINT FK_F4DB719DC55C0FCE FOREIGN KEY (tutor_link_id) REFERENCES internship_tutor_link (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE internship_reminder_batch_item ADD CONSTRAINT FK_F4DB719D3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_reminder_batch_item ADD CONSTRAINT FK_F4DB719D3E8BB15A FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE internship_reminder_batch_item ADD CONSTRAINT FK_F4DB719DD987BE75 FOREIGN KEY (reminder_id) REFERENCES internship_reminder (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE internship_reminder ADD batch_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_reminder ADD CONSTRAINT FK_505725F9F39EBE7A FOREIGN KEY (batch_id) REFERENCES internship_reminder_batch (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_505725F9F39EBE7A ON internship_reminder (batch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_reminder_batch DROP FOREIGN KEY FK_73E94F46D2EECC3F');
        $this->addSql('ALTER TABLE internship_reminder_batch DROP FOREIGN KEY FK_73E94F46B03A8386');
        $this->addSql('ALTER TABLE internship_reminder_batch DROP FOREIGN KEY FK_73E94F46616414BD');
        $this->addSql('ALTER TABLE internship_reminder_batch DROP FOREIGN KEY FK_73E94F46187B2D12');
        $this->addSql('ALTER TABLE internship_reminder_batch_program DROP FOREIGN KEY FK_342D8856BC613D27');
        $this->addSql('ALTER TABLE internship_reminder_batch_program DROP FOREIGN KEY FK_342D88563EB8070A');
        $this->addSql('ALTER TABLE internship_reminder_batch_item DROP FOREIGN KEY FK_F4DB719DF39EBE7A');
        $this->addSql('ALTER TABLE internship_reminder_batch_item DROP FOREIGN KEY FK_F4DB719DC55C0FCE');
        $this->addSql('ALTER TABLE internship_reminder_batch_item DROP FOREIGN KEY FK_F4DB719D3EB8070A');
        $this->addSql('ALTER TABLE internship_reminder_batch_item DROP FOREIGN KEY FK_F4DB719D3E8BB15A');
        $this->addSql('ALTER TABLE internship_reminder_batch_item DROP FOREIGN KEY FK_F4DB719DD987BE75');
        $this->addSql('DROP TABLE internship_reminder_batch');
        $this->addSql('DROP TABLE internship_reminder_batch_program');
        $this->addSql('DROP TABLE internship_reminder_batch_item');
        $this->addSql('ALTER TABLE internship_reminder DROP FOREIGN KEY FK_505725F9F39EBE7A');
        $this->addSql('DROP INDEX IDX_505725F9F39EBE7A ON internship_reminder');
        $this->addSql('ALTER TABLE internship_reminder DROP batch_id');
    }
}
