<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711213114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE assignment (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, due_date DATE NOT NULL, audience_type VARCHAR(20) NOT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, option_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_30C544BA3EB8070A (program_id), INDEX IDX_30C544BAA7C41D6F (option_id), INDEX IDX_30C544BAB03A8386 (created_by_id), INDEX IDX_30C544BAF5A2E305 (inactivated_by_id), INDEX IDX_30C544BAE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE assignment_manual_recipient (assignment_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_16321802D19302F8 (assignment_id), INDEX IDX_16321802A76ED395 (user_id), PRIMARY KEY (assignment_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE assignment_submission (id INT AUTO_INCREMENT NOT NULL, submitted_at DATETIME NOT NULL, assignment_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_E5A63E2CD19302F8 (assignment_id), INDEX IDX_E5A63E2CCB944F1A (student_id), UNIQUE INDEX uniq_assignment_student (assignment_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE assignment_submission_file (id INT AUTO_INCREMENT NOT NULL, storage_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, submission_id INT NOT NULL, INDEX IDX_3B832DE7E1FD4933 (submission_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE assignment_manual_recipient ADD CONSTRAINT FK_16321802D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_manual_recipient ADD CONSTRAINT FK_16321802A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_submission ADD CONSTRAINT FK_E5A63E2CD19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id)');
        $this->addSql('ALTER TABLE assignment_submission ADD CONSTRAINT FK_E5A63E2CCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE assignment_submission_file ADD CONSTRAINT FK_3B832DE7E1FD4933 FOREIGN KEY (submission_id) REFERENCES assignment_submission (id)');
        $this->addSql('ALTER TABLE program ADD assignment_management_enabled TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA3EB8070A');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAA7C41D6F');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAB03A8386');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAF5A2E305');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAE562D849');
        $this->addSql('ALTER TABLE assignment_manual_recipient DROP FOREIGN KEY FK_16321802D19302F8');
        $this->addSql('ALTER TABLE assignment_manual_recipient DROP FOREIGN KEY FK_16321802A76ED395');
        $this->addSql('ALTER TABLE assignment_submission DROP FOREIGN KEY FK_E5A63E2CD19302F8');
        $this->addSql('ALTER TABLE assignment_submission DROP FOREIGN KEY FK_E5A63E2CCB944F1A');
        $this->addSql('ALTER TABLE assignment_submission_file DROP FOREIGN KEY FK_3B832DE7E1FD4933');
        $this->addSql('DROP TABLE assignment');
        $this->addSql('DROP TABLE assignment_manual_recipient');
        $this->addSql('DROP TABLE assignment_submission');
        $this->addSql('DROP TABLE assignment_submission_file');
        $this->addSql('ALTER TABLE program DROP assignment_management_enabled');
    }
}
