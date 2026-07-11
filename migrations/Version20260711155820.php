<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames InternshipSkillGroup/InternshipSkillCriterion to SkillGroup/SkillCriterion (table/column
 * renames only, no drop/recreate - content is preserved), makes skill_group.program_id nullable so
 * a null program can hold the Centre de formation's shared definition, and adds
 * program.custom_skill_criteria_enabled so a Program can opt into defining its own instead.
 */
final class Version20260711155820 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename internship_skill_group(_option)/internship_skill_criterion to skill_group(_option)/skill_criterion, make skill_group.program_id nullable (Centre de formation vs per-Program), add program.custom_skill_criteria_enabled.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('RENAME TABLE internship_skill_group TO skill_group');
        $this->addSql('RENAME TABLE internship_skill_criterion TO skill_criterion');
        $this->addSql('RENAME TABLE internship_skill_group_option TO skill_group_option');
        $this->addSql('ALTER TABLE skill_group_option CHANGE internship_skill_group_id skill_group_id INT NOT NULL');
        $this->addSql('ALTER TABLE skill_group MODIFY program_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD custom_skill_criteria_enabled TINYINT NOT NULL DEFAULT 0');
        // Index names are derived from the table name, so the renames above leave them stamped
        // with the old internship_skill_* hash - realign them with what Doctrine now generates
        // for skill_group(_option)/skill_criterion so schema:validate stays clean.
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX idx_fada1fb1bcfcb4b5 TO IDX_2F73192CBCFCB4B5');
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX idx_fada1fb1b03a8386 TO IDX_2F73192CB03A8386');
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX idx_fada1fb1f5a2e305 TO IDX_2F73192CF5A2E305');
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX idx_fada1fb1e562d849 TO IDX_2F73192CE562D849');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX idx_50c7f0033eb8070a TO IDX_48E8D7F93EB8070A');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX idx_50c7f003b03a8386 TO IDX_48E8D7F9B03A8386');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX idx_50c7f003f5a2e305 TO IDX_48E8D7F9F5A2E305');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX idx_50c7f003e562d849 TO IDX_48E8D7F9E562D849');
        $this->addSql('ALTER TABLE skill_group_option RENAME INDEX idx_17f7dfde75bbcd97 TO IDX_1B6C4862BCFCB4B5');
        $this->addSql('ALTER TABLE skill_group_option RENAME INDEX idx_17f7dfdea7c41d6f TO IDX_1B6C4862A7C41D6F');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE skill_group_option RENAME INDEX IDX_1B6C4862A7C41D6F TO idx_17f7dfdea7c41d6f');
        $this->addSql('ALTER TABLE skill_group_option RENAME INDEX IDX_1B6C4862BCFCB4B5 TO idx_17f7dfde75bbcd97');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX IDX_48E8D7F9E562D849 TO idx_50c7f003e562d849');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX IDX_48E8D7F9F5A2E305 TO idx_50c7f003f5a2e305');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX IDX_48E8D7F9B03A8386 TO idx_50c7f003b03a8386');
        $this->addSql('ALTER TABLE skill_group RENAME INDEX IDX_48E8D7F93EB8070A TO idx_50c7f0033eb8070a');
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX IDX_2F73192CE562D849 TO idx_fada1fb1e562d849');
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX IDX_2F73192CF5A2E305 TO idx_fada1fb1f5a2e305');
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX IDX_2F73192CB03A8386 TO idx_fada1fb1b03a8386');
        $this->addSql('ALTER TABLE skill_criterion RENAME INDEX IDX_2F73192CBCFCB4B5 TO idx_fada1fb1bcfcb4b5');
        $this->addSql('ALTER TABLE program DROP custom_skill_criteria_enabled');
        $this->addSql('ALTER TABLE skill_group MODIFY program_id INT NOT NULL');
        $this->addSql('ALTER TABLE skill_group_option CHANGE skill_group_id internship_skill_group_id INT NOT NULL');
        $this->addSql('RENAME TABLE skill_group_option TO internship_skill_group_option');
        $this->addSql('RENAME TABLE skill_criterion TO internship_skill_criterion');
        $this->addSql('RENAME TABLE skill_group TO internship_skill_group');
    }
}
