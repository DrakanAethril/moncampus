<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * e-CO: « Balise introuvable » - a runner's report of a flag missing from the terrain, and the
 * flags a teacher neutralised for a race.
 */
final class Version20261125212642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'e-CO: checkpoint reports and neutralised checkpoints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE eco_checkpoint_report (id INT AUTO_INCREMENT NOT NULL, reported_at DATETIME NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, runner_id INT NOT NULL, checkpoint_id INT NOT NULL, INDEX IDX_B1AC7F023C7FB593 (runner_id), INDEX IDX_B1AC7F02F27C615F (checkpoint_id), UNIQUE INDEX uniq_eco_checkpoint_report_runner_checkpoint (runner_id, checkpoint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_course_neutralized_checkpoint (course_id INT NOT NULL, checkpoint_id INT NOT NULL, INDEX IDX_55382211591CC992 (course_id), INDEX IDX_55382211F27C615F (checkpoint_id), PRIMARY KEY (course_id, checkpoint_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE eco_checkpoint_report ADD CONSTRAINT FK_B1AC7F023C7FB593 FOREIGN KEY (runner_id) REFERENCES eco_runner (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_checkpoint_report ADD CONSTRAINT FK_B1AC7F02F27C615F FOREIGN KEY (checkpoint_id) REFERENCES eco_checkpoint (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_course_neutralized_checkpoint ADD CONSTRAINT FK_55382211591CC992 FOREIGN KEY (course_id) REFERENCES eco_course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_course_neutralized_checkpoint ADD CONSTRAINT FK_55382211F27C615F FOREIGN KEY (checkpoint_id) REFERENCES eco_checkpoint (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_checkpoint_report DROP FOREIGN KEY FK_B1AC7F023C7FB593');
        $this->addSql('ALTER TABLE eco_checkpoint_report DROP FOREIGN KEY FK_B1AC7F02F27C615F');
        $this->addSql('ALTER TABLE eco_course_neutralized_checkpoint DROP FOREIGN KEY FK_55382211591CC992');
        $this->addSql('ALTER TABLE eco_course_neutralized_checkpoint DROP FOREIGN KEY FK_55382211F27C615F');
        $this->addSql('DROP TABLE eco_checkpoint_report');
        $this->addSql('DROP TABLE eco_course_neutralized_checkpoint');
    }
}
