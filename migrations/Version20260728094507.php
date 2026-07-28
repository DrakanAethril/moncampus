<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260728094507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ContractType (fixed apprentissage/professionnalisation, center-level default modalities) and ProgramContractModality (per-Program override) to replace InternshipProgramInfo::termsConditionsProText/termsConditionsApprentissageText, and add org-identity fields to InternshipFormationCenter.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE contract_type (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(30) NOT NULL,
              default_modalities_html LONGTEXT DEFAULT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_E4AB194177153098 (code),
              INDEX IDX_E4AB1941B03A8386 (created_by_id),
              INDEX IDX_E4AB1941F5A2E305 (inactivated_by_id),
              INDEX IDX_E4AB1941E562D849 (last_updated_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE program_contract_modality (
              id INT AUTO_INCREMENT NOT NULL,
              modalities_html LONGTEXT NOT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              program_id INT NOT NULL,
              contract_type_id INT NOT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              INDEX IDX_461B5A2F3EB8070A (program_id),
              INDEX IDX_461B5A2FCD1DF15B (contract_type_id),
              INDEX IDX_461B5A2FB03A8386 (created_by_id),
              INDEX IDX_461B5A2FF5A2E305 (inactivated_by_id),
              INDEX IDX_461B5A2FE562D849 (last_updated_by_id),
              UNIQUE INDEX program_contract_modality_unique (program_id, contract_type_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              contract_type
            ADD
              CONSTRAINT FK_E4AB1941B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              contract_type
            ADD
              CONSTRAINT FK_E4AB1941F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              contract_type
            ADD
              CONSTRAINT FK_E4AB1941E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_contract_modality
            ADD
              CONSTRAINT FK_461B5A2F3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_contract_modality
            ADD
              CONSTRAINT FK_461B5A2FCD1DF15B FOREIGN KEY (contract_type_id) REFERENCES contract_type (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_contract_modality
            ADD
              CONSTRAINT FK_461B5A2FB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_contract_modality
            ADD
              CONSTRAINT FK_461B5A2FF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              program_contract_modality
            ADD
              CONSTRAINT FK_461B5A2FE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_formation_center
            ADD
              company_name VARCHAR(255) DEFAULT NULL,
            ADD
              address VARCHAR(255) DEFAULT NULL,
            ADD
              postal_code VARCHAR(20) DEFAULT NULL,
            ADD
              city VARCHAR(255) DEFAULT NULL,
            ADD
              phone VARCHAR(30) DEFAULT NULL,
            ADD
              email VARCHAR(255) DEFAULT NULL
        SQL);
        // Seed the two fixed ContractType rows - no "system" user to attribute them to, same
        // MIN(id) fallback as Version20260715051829's laptop_condition_type backfill. Their
        // default_modalities_html starts blank (center-level defaults are filled in later via
        // "Configuration > Modalités de contrats"); every Program's pre-existing free-text values
        // become per-Program overrides below instead, since they were never actually shared
        // defaults to begin with.
        $this->addSql(<<<'SQL'
            INSERT INTO contract_type (code, created_by_id)
            SELECT 'apprentissage', MIN(id) FROM `user`
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO contract_type (code, created_by_id)
            SELECT 'professionnalisation', MIN(id) FROM `user`
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO program_contract_modality (program_id, contract_type_id, modalities_html, created_by_id)
            SELECT info.program_id, (SELECT id FROM contract_type WHERE code = 'apprentissage'), info.terms_conditions_apprentissage_text, MIN(u.id)
            FROM internship_program_info info, `user` u
            WHERE info.terms_conditions_apprentissage_text IS NOT NULL AND info.terms_conditions_apprentissage_text != ''
            GROUP BY info.program_id, info.terms_conditions_apprentissage_text
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO program_contract_modality (program_id, contract_type_id, modalities_html, created_by_id)
            SELECT info.program_id, (SELECT id FROM contract_type WHERE code = 'professionnalisation'), info.terms_conditions_pro_text, MIN(u.id)
            FROM internship_program_info info, `user` u
            WHERE info.terms_conditions_pro_text IS NOT NULL AND info.terms_conditions_pro_text != ''
            GROUP BY info.program_id, info.terms_conditions_pro_text
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_program_info
            DROP
              terms_conditions_pro_text,
            DROP
              terms_conditions_apprentissage_text
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_program_info
            ADD
              terms_conditions_pro_text LONGTEXT DEFAULT NULL,
            ADD
              terms_conditions_apprentissage_text LONGTEXT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE internship_program_info info
            JOIN program_contract_modality m ON m.program_id = info.program_id
            JOIN contract_type ct ON ct.id = m.contract_type_id AND ct.code = 'professionnalisation'
            SET info.terms_conditions_pro_text = m.modalities_html
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE internship_program_info info
            JOIN program_contract_modality m ON m.program_id = info.program_id
            JOIN contract_type ct ON ct.id = m.contract_type_id AND ct.code = 'apprentissage'
            SET info.terms_conditions_apprentissage_text = m.modalities_html
        SQL);

        $this->addSql('ALTER TABLE contract_type DROP FOREIGN KEY FK_E4AB1941B03A8386');
        $this->addSql('ALTER TABLE contract_type DROP FOREIGN KEY FK_E4AB1941F5A2E305');
        $this->addSql('ALTER TABLE contract_type DROP FOREIGN KEY FK_E4AB1941E562D849');
        $this->addSql('ALTER TABLE program_contract_modality DROP FOREIGN KEY FK_461B5A2F3EB8070A');
        $this->addSql('ALTER TABLE program_contract_modality DROP FOREIGN KEY FK_461B5A2FCD1DF15B');
        $this->addSql('ALTER TABLE program_contract_modality DROP FOREIGN KEY FK_461B5A2FB03A8386');
        $this->addSql('ALTER TABLE program_contract_modality DROP FOREIGN KEY FK_461B5A2FF5A2E305');
        $this->addSql('ALTER TABLE program_contract_modality DROP FOREIGN KEY FK_461B5A2FE562D849');
        $this->addSql('DROP TABLE contract_type');
        $this->addSql('DROP TABLE program_contract_modality');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_formation_center
            DROP
              company_name,
            DROP
              address,
            DROP
              postal_code,
            DROP
              city,
            DROP
              phone,
            DROP
              email
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_program_info
            ADD
              terms_conditions_pro_text LONGTEXT DEFAULT NULL,
            ADD
              terms_conditions_apprentissage_text LONGTEXT DEFAULT NULL
        SQL);
    }
}
