<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Declared engagement - design/validated/gamification.md, lot 5.
 *
 * **Useful outside the game: it is a portfolio.** A certification passed, a JPO stood at, a project
 * presented, a classmate tutored - what somebody actually did over two years, with the evidence
 * attached and an adult's signature on each line. Refusals are kept with their reason, which is what
 * stops the same thing from being re-filed three times in the hope of another reviewer.
 *
 * The attachment carries a storage key and a name, like every other attachment here: the file
 * reached the bucket on its own request, before the form was ever submitted.
 */
final class Version20260827160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Declared engagement of the campus game, and its evidence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE engagement_declaration (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, description LONGTEXT NOT NULL, state VARCHAR(20) NOT NULL, reviewed_at DATETIME DEFAULT NULL, refusal_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, reviewer_id INT DEFAULT NULL, INDEX IDX_C165F0BFCB944F1A (student_id), INDEX IDX_C165F0BF3EB8070A (program_id), INDEX IDX_C165F0BFEC8B7ADE (period_id), INDEX IDX_C165F0BF70574616 (reviewer_id), INDEX idx_engagement_program_period_state (program_id, period_id, state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE engagement_declaration_attachment (id INT AUTO_INCREMENT NOT NULL, storage_key VARCHAR(500) NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, declaration_id INT NOT NULL, INDEX IDX_66D3AC5DC06258A3 (declaration_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE engagement_declaration ADD CONSTRAINT FK_C165F0BFCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engagement_declaration ADD CONSTRAINT FK_C165F0BF3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engagement_declaration ADD CONSTRAINT FK_C165F0BFEC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engagement_declaration ADD CONSTRAINT FK_C165F0BF70574616 FOREIGN KEY (reviewer_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE engagement_declaration_attachment ADD CONSTRAINT FK_66D3AC5DC06258A3 FOREIGN KEY (declaration_id) REFERENCES engagement_declaration (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE engagement_declaration_attachment');
        $this->addSql('DROP TABLE engagement_declaration');
    }
}
