<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The two relation tables between a quiz of the library and what uses it - a séance, or a whole
 * séquence (design/comparaison/conception_sequence_seance_ia.md, § 8.1).
 *
 * **Two tables and not two nullable foreign keys on quiz_template**, because what they feed - the
 * « 2 séances sur 4 » of the séquence's quiz card - measures *usage* and not provenance, and the two do
 * not have the same cardinality. A réactivation quiz serves in S2 *and* in S3; a séance carries a
 * diagnostic at its opening and a final at its end. With one column per level, making a quiz serve two
 * séances would mean duplicating the quiz.
 *
 * **Two tables and not one with a nullable seance_template_id**: the Ansible kit's final QCM is about
 * the whole séquence and about no séance in particular, so a half-absent key would be a table that
 * means two things.
 *
 * `ON DELETE CASCADE` on all four columns, and that is the behaviour worth naming: deleting a séance
 * *detaches* the quiz, it never deletes it. A quiz belongs to the teacher's library, which is its home;
 * a séance only ever borrows it.
 *
 * Nothing to backfill - no link existed before this.
 */
final class Version20260813074906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rattachement des quiz de la bibliothèque aux séances et aux séquences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quiz_template_seance_template (quiz_template_id INT NOT NULL, seance_template_id INT NOT NULL, INDEX IDX_3EF13B582AFC1C18 (quiz_template_id), INDEX IDX_3EF13B583808C4F3 (seance_template_id), PRIMARY KEY (quiz_template_id, seance_template_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_template_sequence_template (quiz_template_id INT NOT NULL, sequence_template_id INT NOT NULL, INDEX IDX_4BCD825D2AFC1C18 (quiz_template_id), INDEX IDX_4BCD825DA31F2F3E (sequence_template_id), PRIMARY KEY (quiz_template_id, sequence_template_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_template_seance_template ADD CONSTRAINT FK_3EF13B582AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_template_seance_template ADD CONSTRAINT FK_3EF13B583808C4F3 FOREIGN KEY (seance_template_id) REFERENCES seance_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_template_sequence_template ADD CONSTRAINT FK_4BCD825D2AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_template_sequence_template ADD CONSTRAINT FK_4BCD825DA31F2F3E FOREIGN KEY (sequence_template_id) REFERENCES sequence_template (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_template_seance_template DROP FOREIGN KEY FK_3EF13B582AFC1C18');
        $this->addSql('ALTER TABLE quiz_template_seance_template DROP FOREIGN KEY FK_3EF13B583808C4F3');
        $this->addSql('ALTER TABLE quiz_template_sequence_template DROP FOREIGN KEY FK_4BCD825D2AFC1C18');
        $this->addSql('ALTER TABLE quiz_template_sequence_template DROP FOREIGN KEY FK_4BCD825DA31F2F3E');
        $this->addSql('DROP TABLE quiz_template_seance_template');
        $this->addSql('DROP TABLE quiz_template_sequence_template');
    }
}
