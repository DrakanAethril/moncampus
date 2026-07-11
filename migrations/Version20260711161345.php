<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711161345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add skill_option (0..many Options per Skill, same shape as skill_group_option) - empty means the skill concerns every student on the Program.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE skill_option (skill_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_75CC54A45585C142 (skill_id), INDEX IDX_75CC54A4A7C41D6F (option_id), PRIMARY KEY (skill_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE skill_option ADD CONSTRAINT FK_75CC54A45585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE skill_option ADD CONSTRAINT FK_75CC54A4A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE skill_option DROP FOREIGN KEY FK_75CC54A45585C142');
        $this->addSql('ALTER TABLE skill_option DROP FOREIGN KEY FK_75CC54A4A7C41D6F');
        $this->addSql('DROP TABLE skill_option');
    }
}
