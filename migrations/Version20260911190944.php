<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Topic::$teacher (one titulaire) becomes Topic::$teachers (several), so two teachers can hold the
 * same matière and each keep their own evaluations inside its carnet de notes.
 *
 * The order of the three steps is the whole point: the join table is created, every existing
 * teacher_id is copied into it, and only then is the column dropped. A diff-generated migration
 * writes the first and the last and loses every assignment in production - this one must never be
 * regenerated from doctrine:migrations:diff.
 *
 * down() is deliberately lossy and says so: a matière that gained a second titulaire cannot go back
 * to holding one, so the lowest teacher id is kept and the others are dropped.
 */
final class Version20260911190944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plusieurs enseignants titulaires par matière (topic_teacher), en conservant les affectations existantes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE topic_teacher (topic_id INT NOT NULL, teacher_id INT NOT NULL, INDEX IDX_385DFB001F55203D (topic_id), INDEX IDX_385DFB0041807E1D (teacher_id), PRIMARY KEY (topic_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE topic_teacher ADD CONSTRAINT FK_385DFB001F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE topic_teacher ADD CONSTRAINT FK_385DFB0041807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id) ON DELETE CASCADE');

        // The data, before the column that holds it goes. A no-op on an empty database, which is
        // what CI replays the history onto.
        $this->addSql('INSERT INTO topic_teacher (topic_id, teacher_id) SELECT id, teacher_id FROM topic WHERE teacher_id IS NOT NULL');

        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY `FK_9D40DE1B41807E1D`');
        $this->addSql('DROP INDEX IDX_9D40DE1B41807E1D ON topic');
        $this->addSql('ALTER TABLE topic DROP teacher_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE topic ADD teacher_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE topic ADD CONSTRAINT `FK_9D40DE1B41807E1D` FOREIGN KEY (teacher_id) REFERENCES `user` (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_9D40DE1B41807E1D ON topic (teacher_id)');

        // Lossy on purpose - see the class docblock.
        $this->addSql('UPDATE topic t SET t.teacher_id = (SELECT MIN(tt.teacher_id) FROM topic_teacher tt WHERE tt.topic_id = t.id)');

        $this->addSql('ALTER TABLE topic_teacher DROP FOREIGN KEY FK_385DFB001F55203D');
        $this->addSql('ALTER TABLE topic_teacher DROP FOREIGN KEY FK_385DFB0041807E1D');
        $this->addSql('DROP TABLE topic_teacher');
    }
}
