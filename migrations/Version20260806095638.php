<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'assistant de création d'un travail (design_handoff_creation_travail 2a) : ce qu'un travail
 * annonce désormais en plus de son titre et de son échéance.
 *
 * Trois familles s'ajoutent à `assignment` : son caractère et sa notation (obligatoire/facultatif,
 * noté/non noté et la visibilité de ce choix), les règles de dépôt (retard autorisé, suivi de
 * lecture), et ses rattachements (matière, lot de groupes visé, évaluation créée au carnet). Deux
 * tables filles portent ce qu'un travail peut avoir en plusieurs exemplaires : les productions
 * attendues, chacune avec son format et éventuellement sa propre échéance, et les supports joints.
 *
 * Les travaux existants sont repris à leur sens d'avant, et non aux valeurs par défaut du nouvel
 * écran : obligatoires (ce qu'ils étaient tous), non notés (aucun n'a jamais fait naître
 * d'évaluation au carnet), dépôt en retard fermé, suivi de lecture ouvert - la trace d'ouverture
 * étant déjà écrite pour eux par AssignmentView.
 */
final class Version20260806095638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Caractère, notation, productions attendues et supports d'un travail (2a).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assignment_attachment (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, assignment_id INT NOT NULL, INDEX IDX_47FCBD64D19302F8 (assignment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE assignment_expected_production (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, format VARCHAR(20) NOT NULL, due_date DATETIME DEFAULT NULL, position INT NOT NULL, assignment_id INT NOT NULL, INDEX IDX_E29E2842D19302F8 (assignment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assignment_attachment ADD CONSTRAINT FK_47FCBD64D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_expected_production ADD CONSTRAINT FK_E29E2842D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        // Les DEFAULT portent la reprise des travaux existants (voir le docblock) autant que la
        // valeur d'une insertion qui ne les nommerait pas.
        $this->addSql('ALTER TABLE assignment ADD mandatory TINYINT NOT NULL DEFAULT 1, ADD graded TINYINT NOT NULL DEFAULT 0, ADD grading_visible_to_students TINYINT NOT NULL DEFAULT 1, ADD late_submission_allowed TINYINT NOT NULL DEFAULT 0, ADD read_tracking_enabled TINYINT NOT NULL DEFAULT 1, ADD topic_id INT DEFAULT NULL, ADD group_batch_id INT DEFAULT NULL, ADD gradebook_evaluation_id INT DEFAULT NULL');
        // La matière d'un travail déjà donné depuis une séance se déduit de cette séance - c'est
        // elle qui la portait, et le travail la reprend telle quelle.
        $this->addSql('UPDATE assignment a INNER JOIN lesson_session s ON s.id = a.lesson_session_id SET a.topic_id = s.topic_id WHERE s.topic_id IS NOT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA1F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAFAAEF205 FOREIGN KEY (group_batch_id) REFERENCES group_batch (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA49C2DDF5 FOREIGN KEY (gradebook_evaluation_id) REFERENCES evaluation (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BA1F55203D ON assignment (topic_id)');
        $this->addSql('CREATE INDEX IDX_30C544BAFAAEF205 ON assignment (group_batch_id)');
        $this->addSql('CREATE INDEX IDX_30C544BA49C2DDF5 ON assignment (gradebook_evaluation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_attachment DROP FOREIGN KEY FK_47FCBD64D19302F8');
        $this->addSql('ALTER TABLE assignment_expected_production DROP FOREIGN KEY FK_E29E2842D19302F8');
        $this->addSql('DROP TABLE assignment_attachment');
        $this->addSql('DROP TABLE assignment_expected_production');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA1F55203D');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAFAAEF205');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA49C2DDF5');
        $this->addSql('DROP INDEX IDX_30C544BA1F55203D ON assignment');
        $this->addSql('DROP INDEX IDX_30C544BAFAAEF205 ON assignment');
        $this->addSql('DROP INDEX IDX_30C544BA49C2DDF5 ON assignment');
        $this->addSql('ALTER TABLE assignment DROP mandatory, DROP graded, DROP grading_visible_to_students, DROP late_submission_allowed, DROP read_tracking_enabled, DROP topic_id, DROP group_batch_id, DROP gradebook_evaluation_id');
    }
}
