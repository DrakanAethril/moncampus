<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712060533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SkillGroup/Skill become always Program-owned (no more Centre de formation shared '
            .'variant); InternshipSkillLevel gains that shared/opt-out mechanism instead, via the '
            .'renamed Program.customSkillLevelsEnabled flag.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_skill_level ADD program_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_skill_level ADD CONSTRAINT FK_A7ED78D53EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('CREATE INDEX IDX_A7ED78D53EB8070A ON internship_skill_level (program_id)');
        $this->addSql('ALTER TABLE program CHANGE custom_skill_criteria_enabled custom_skill_levels_enabled TINYINT DEFAULT 0 NOT NULL');
        // The renamed column keeps whatever boolean value each Program had for the *old* meaning
        // (custom SkillGroup/Skill lists) - that value has nothing to do with the *new* meaning
        // (custom skill levels) and no Program has ever actually opted into that yet, so every
        // row is reset to the new field's own default (false) rather than silently inheriting a
        // stale decision made about a different concept.
        $this->addSql('UPDATE program SET custom_skill_levels_enabled = 0');
        $this->addSql('ALTER TABLE skill_group CHANGE program_id program_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_skill_level DROP FOREIGN KEY FK_A7ED78D53EB8070A');
        $this->addSql('DROP INDEX IDX_A7ED78D53EB8070A ON internship_skill_level');
        $this->addSql('ALTER TABLE internship_skill_level DROP program_id');
        $this->addSql('ALTER TABLE program CHANGE custom_skill_levels_enabled custom_skill_criteria_enabled TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE skill_group CHANGE program_id program_id INT DEFAULT NULL');
    }
}
