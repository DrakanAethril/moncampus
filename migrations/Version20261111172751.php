<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The CRM des inscriptions: its settings (years opened, formations followed, the piece catalogue,
 * the origins), and the dossiers with their pieces, journal and modality history.
 *
 * Schema only; the catalogue it ships with is Version20261111172800's.
 */
final class Version20261111172751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the CRM des inscriptions tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE crm_candidate (id INT AUTO_INCREMENT NOT NULL, last_name VARCHAR(120) NOT NULL, first_name VARCHAR(120) NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, foreign_student TINYINT NOT NULL, guardians JSON NOT NULL, INDEX idx_crm_candidate_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_dossier (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(20) NOT NULL, modality VARCHAR(20) NOT NULL, situation VARCHAR(20) NOT NULL, prior_schooling VARCHAR(255) DEFAULT NULL, sfp TINYINT NOT NULL, refund VARCHAR(10) DEFAULT NULL, opened_on DATE NOT NULL, created_at DATETIME NOT NULL, candidate_id INT NOT NULL, program_id INT NOT NULL, option_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_D103ACA091BD8781 (candidate_id), INDEX IDX_D103ACA03EB8070A (program_id), INDEX IDX_D103ACA0A7C41D6F (option_id), INDEX IDX_D103ACA0B03A8386 (created_by_id), INDEX idx_crm_dossier_program_situation (program_id, situation), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_dossier_origin (crm_dossier_id INT NOT NULL, crm_origin_id INT NOT NULL, INDEX IDX_D7FF174A24823ADE (crm_dossier_id), INDEX IDX_D7FF174A17C88EE1 (crm_origin_id), PRIMARY KEY (crm_dossier_id, crm_origin_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_dossier_piece (id INT AUTO_INCREMENT NOT NULL, done_on DATE DEFAULT NULL, value JSON DEFAULT NULL, payment_mode VARCHAR(40) DEFAULT NULL, entered_by_name VARCHAR(160) NOT NULL, entered_at DATETIME NOT NULL, imported TINYINT NOT NULL, dossier_id INT NOT NULL, piece_id INT NOT NULL, entered_by_id INT DEFAULT NULL, INDEX IDX_FCC7742F611C0C56 (dossier_id), INDEX IDX_FCC7742FC40FCFA8 (piece_id), INDEX IDX_FCC7742FC443EDD (entered_by_id), UNIQUE INDEX uniq_crm_dossier_piece (dossier_id, piece_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_journal_entry (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, text LONGTEXT NOT NULL, occurred_on DATE NOT NULL, author_name VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL, dossier_id INT NOT NULL, author_id INT DEFAULT NULL, piece_id INT DEFAULT NULL, INDEX IDX_B977CB11611C0C56 (dossier_id), INDEX IDX_B977CB11F675F31B (author_id), INDEX IDX_B977CB11C40FCFA8 (piece_id), INDEX idx_crm_journal_occurred (occurred_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_modality_change (id INT AUTO_INCREMENT NOT NULL, modality VARCHAR(20) NOT NULL, effective_on DATE NOT NULL, author_name VARCHAR(160) NOT NULL, reason VARCHAR(255) DEFAULT NULL, dossier_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_AD261816611C0C56 (dossier_id), INDEX IDX_AD261816F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_origin (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, position INT NOT NULL, UNIQUE INDEX uniq_crm_origin_label (label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_piece (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, short_label VARCHAR(30) NOT NULL, phase VARCHAR(20) NOT NULL, kind VARCHAR(20) NOT NULL, modalities JSON NOT NULL, paths JSON NOT NULL, foreign_only TINYINT NOT NULL, default_constraint VARCHAR(20) NOT NULL, future_date_allowed TINYINT NOT NULL, choices JSON NOT NULL, last_value_completes TINYINT NOT NULL, asks_payment_mode TINYINT NOT NULL, position INT NOT NULL, UNIQUE INDEX uniq_crm_piece_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_program (id INT AUTO_INCREMENT NOT NULL, followed TINYINT NOT NULL, default_modality VARCHAR(20) NOT NULL, option_required TINYINT NOT NULL, deposit_amount INT DEFAULT NULL, npec_amount INT DEFAULT NULL, option_amounts JSON NOT NULL, piece_constraints JSON NOT NULL, program_id INT NOT NULL, UNIQUE INDEX uniq_crm_program (program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE crm_school_year (id INT AUTO_INCREMENT NOT NULL, opened TINYINT NOT NULL, is_default TINYINT NOT NULL, school_year_id INT NOT NULL, UNIQUE INDEX uniq_crm_school_year (school_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE crm_dossier ADD CONSTRAINT FK_D103ACA091BD8781 FOREIGN KEY (candidate_id) REFERENCES crm_candidate (id)');
        $this->addSql('ALTER TABLE crm_dossier ADD CONSTRAINT FK_D103ACA03EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE crm_dossier ADD CONSTRAINT FK_D103ACA0A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_dossier ADD CONSTRAINT FK_D103ACA0B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_dossier_origin ADD CONSTRAINT FK_D7FF174A24823ADE FOREIGN KEY (crm_dossier_id) REFERENCES crm_dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_dossier_origin ADD CONSTRAINT FK_D7FF174A17C88EE1 FOREIGN KEY (crm_origin_id) REFERENCES crm_origin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_dossier_piece ADD CONSTRAINT FK_FCC7742F611C0C56 FOREIGN KEY (dossier_id) REFERENCES crm_dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_dossier_piece ADD CONSTRAINT FK_FCC7742FC40FCFA8 FOREIGN KEY (piece_id) REFERENCES crm_piece (id)');
        $this->addSql('ALTER TABLE crm_dossier_piece ADD CONSTRAINT FK_FCC7742FC443EDD FOREIGN KEY (entered_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_journal_entry ADD CONSTRAINT FK_B977CB11611C0C56 FOREIGN KEY (dossier_id) REFERENCES crm_dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_journal_entry ADD CONSTRAINT FK_B977CB11F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_journal_entry ADD CONSTRAINT FK_B977CB11C40FCFA8 FOREIGN KEY (piece_id) REFERENCES crm_piece (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_modality_change ADD CONSTRAINT FK_AD261816611C0C56 FOREIGN KEY (dossier_id) REFERENCES crm_dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_modality_change ADD CONSTRAINT FK_AD261816F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE crm_program ADD CONSTRAINT FK_7EA63B133EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE crm_school_year ADD CONSTRAINT FK_198C39FED2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_dossier DROP FOREIGN KEY FK_D103ACA091BD8781');
        $this->addSql('ALTER TABLE crm_dossier DROP FOREIGN KEY FK_D103ACA03EB8070A');
        $this->addSql('ALTER TABLE crm_dossier DROP FOREIGN KEY FK_D103ACA0A7C41D6F');
        $this->addSql('ALTER TABLE crm_dossier DROP FOREIGN KEY FK_D103ACA0B03A8386');
        $this->addSql('ALTER TABLE crm_dossier_origin DROP FOREIGN KEY FK_D7FF174A24823ADE');
        $this->addSql('ALTER TABLE crm_dossier_origin DROP FOREIGN KEY FK_D7FF174A17C88EE1');
        $this->addSql('ALTER TABLE crm_dossier_piece DROP FOREIGN KEY FK_FCC7742F611C0C56');
        $this->addSql('ALTER TABLE crm_dossier_piece DROP FOREIGN KEY FK_FCC7742FC40FCFA8');
        $this->addSql('ALTER TABLE crm_dossier_piece DROP FOREIGN KEY FK_FCC7742FC443EDD');
        $this->addSql('ALTER TABLE crm_journal_entry DROP FOREIGN KEY FK_B977CB11611C0C56');
        $this->addSql('ALTER TABLE crm_journal_entry DROP FOREIGN KEY FK_B977CB11F675F31B');
        $this->addSql('ALTER TABLE crm_journal_entry DROP FOREIGN KEY FK_B977CB11C40FCFA8');
        $this->addSql('ALTER TABLE crm_modality_change DROP FOREIGN KEY FK_AD261816611C0C56');
        $this->addSql('ALTER TABLE crm_modality_change DROP FOREIGN KEY FK_AD261816F675F31B');
        $this->addSql('ALTER TABLE crm_program DROP FOREIGN KEY FK_7EA63B133EB8070A');
        $this->addSql('ALTER TABLE crm_school_year DROP FOREIGN KEY FK_198C39FED2EECC3F');
        $this->addSql('DROP TABLE crm_candidate');
        $this->addSql('DROP TABLE crm_dossier');
        $this->addSql('DROP TABLE crm_dossier_origin');
        $this->addSql('DROP TABLE crm_dossier_piece');
        $this->addSql('DROP TABLE crm_journal_entry');
        $this->addSql('DROP TABLE crm_modality_change');
        $this->addSql('DROP TABLE crm_origin');
        $this->addSql('DROP TABLE crm_piece');
        $this->addSql('DROP TABLE crm_program');
        $this->addSql('DROP TABLE crm_school_year');
    }
}
