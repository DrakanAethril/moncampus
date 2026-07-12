<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712132411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename internship_skill_level to skill_level (App\Entity\SkillLevel) - real table rename, not drop+recreate, to preserve existing rows and the FK from internship_tutor_evaluation_skill';
    }

    public function up(Schema $schema): void
    {
        // A straight rename (not the auto-generated DROP TABLE + CREATE TABLE Doctrine produced,
        // which would have destroyed the existing rows) - InnoDB keeps
        // internship_tutor_evaluation_skill.skill_level_id's foreign key valid automatically
        // across a RENAME TABLE, no further action needed for that side.
        $this->addSql('RENAME TABLE internship_skill_level TO skill_level');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('RENAME TABLE skill_level TO internship_skill_level');
    }
}
