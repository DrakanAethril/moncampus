<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Balises spécifiques »: the flags a course is run on get a rank of their own. The join table
 * eco_course_checkpoint knew only which flags; eco_course_specific_checkpoint also says in which
 * order the race takes them, which is no longer presumed to be the parcours'.
 *
 * The courses already set up keep the order they were run in until now - the flags' positions in
 * the parcours - numbered 1..n per course. Portable SQL (production runs MariaDB).
 */
final class Version20261118193343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'e-CO: rank of each flag of a « Balises spécifiques » course';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE eco_course_specific_checkpoint (id INT AUTO_INCREMENT NOT NULL, race_rank INT NOT NULL, course_id INT NOT NULL, checkpoint_id INT NOT NULL, INDEX IDX_FA3A04AC591CC992 (course_id), INDEX IDX_FA3A04ACF27C615F (checkpoint_id), UNIQUE INDEX eco_course_specific_checkpoint_unique (course_id, checkpoint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE eco_course_specific_checkpoint ADD CONSTRAINT FK_FA3A04AC591CC992 FOREIGN KEY (course_id) REFERENCES eco_course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_course_specific_checkpoint ADD CONSTRAINT FK_FA3A04ACF27C615F FOREIGN KEY (checkpoint_id) REFERENCES eco_checkpoint (id) ON DELETE CASCADE');

        // A flag's rank is the number of the course's flags at or before it in the parcours (ties
        // on position broken by id, so two flags never share a rank).
        $this->addSql(<<<'SQL'
            INSERT INTO eco_course_specific_checkpoint (course_id, checkpoint_id, race_rank)
            SELECT link.course_id, link.checkpoint_id, COUNT(*)
            FROM eco_course_checkpoint link
            INNER JOIN eco_checkpoint flag ON flag.id = link.checkpoint_id
            INNER JOIN eco_course_checkpoint other_link ON other_link.course_id = link.course_id
            INNER JOIN eco_checkpoint other_flag ON other_flag.id = other_link.checkpoint_id
                AND (other_flag.position < flag.position OR (other_flag.position = flag.position AND other_flag.id <= flag.id))
            GROUP BY link.course_id, link.checkpoint_id
            SQL);

        $this->addSql('ALTER TABLE eco_course_checkpoint DROP FOREIGN KEY FK_7CEC5B32591CC992');
        $this->addSql('ALTER TABLE eco_course_checkpoint DROP FOREIGN KEY FK_7CEC5B32F27C615F');
        $this->addSql('DROP TABLE eco_course_checkpoint');
    }

    public function down(Schema $schema): void
    {
        // The rank is lost: the old table knew no order.
        $this->addSql('CREATE TABLE eco_course_checkpoint (course_id INT NOT NULL, checkpoint_id INT NOT NULL, INDEX IDX_7CEC5B32591CC992 (course_id), INDEX IDX_7CEC5B32F27C615F (checkpoint_id), PRIMARY KEY (course_id, checkpoint_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE eco_course_checkpoint ADD CONSTRAINT FK_7CEC5B32591CC992 FOREIGN KEY (course_id) REFERENCES eco_course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_course_checkpoint ADD CONSTRAINT FK_7CEC5B32F27C615F FOREIGN KEY (checkpoint_id) REFERENCES eco_checkpoint (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO eco_course_checkpoint (course_id, checkpoint_id) SELECT course_id, checkpoint_id FROM eco_course_specific_checkpoint');
        $this->addSql('ALTER TABLE eco_course_specific_checkpoint DROP FOREIGN KEY FK_FA3A04AC591CC992');
        $this->addSql('ALTER TABLE eco_course_specific_checkpoint DROP FOREIGN KEY FK_FA3A04ACF27C615F');
        $this->addSql('DROP TABLE eco_course_specific_checkpoint');
    }
}
