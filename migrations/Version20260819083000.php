<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Co-animation - the second formateur named on a progression
 * (design/validated/co-animation.md).
 *
 * **This one join table is the whole schema change of the feature**, and that is the point rather
 * than an accident: the planning machinery already handles two teachers delivering the same
 * content. Créneaux are chosen by matière and never by teacher, a séance already duplicates once
 * per group with one placement naming each Option, and the Qualiopi export already counts two
 * groups doing the same hour as one learner hour. What was missing was never planning - it was
 * ownership.
 *
 * It hangs off the PROGRESSION and not off the Topic on purpose. « Qui enseigne cette matière » is
 * already derivable, and already derived, from the timetable
 * (TopicRepository::findTaughtByTeacherInProgram() reads the créneaux); storing it a second time
 * would be a second truth to keep correct, and the timetable would win every argument anyway. What
 * the timetable cannot answer is « qui a le droit de modifier le plan », so that is the one fact
 * stored, and it is stored where it applies.
 *
 * Both foreign keys cascade. A deleted progression must not leave a row granting edit rights on
 * nothing, and a deleted account must not keep a name on a plan - neither side of this table means
 * anything without the other, which is exactly when a cascade is the right answer rather than a
 * convenience.
 *
 * No `created_at`, no `created_by`: the link is a right, not an event. The progression's own audit
 * trail records what each teacher then did with it.
 */
final class Version20260819083000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Co-animation: progression_co_teacher, the second formateur allowed to edit a progression';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE progression_co_teacher (progression_id INT NOT NULL, teacher_id INT NOT NULL, INDEX IDX_6EECD310AF229C18 (progression_id), INDEX IDX_6EECD31041807E1D (teacher_id), PRIMARY KEY (progression_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE progression_co_teacher ADD CONSTRAINT FK_6EECD310AF229C18 FOREIGN KEY (progression_id) REFERENCES progression (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE progression_co_teacher ADD CONSTRAINT FK_6EECD31041807E1D FOREIGN KEY (teacher_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_co_teacher DROP FOREIGN KEY FK_6EECD310AF229C18');
        $this->addSql('ALTER TABLE progression_co_teacher DROP FOREIGN KEY FK_6EECD31041807E1D');
        $this->addSql('DROP TABLE progression_co_teacher');
    }
}
