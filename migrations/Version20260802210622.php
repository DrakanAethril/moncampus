<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802210622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cahier de texte : visibilité par temps, travaux rattachés à une séance, échéance horodatée, « marquer comme fait ».';
    }

    /**
     * The columns added are first laid down with a default value, long enough for the existing rows
     * to receive it, then the default is dropped - the Doctrine model declares none, and a later diff
     * would otherwise want to remove it again.
     *
     * The values chosen for the existing rows carry the previous behavior over:
     *  - a cahier de texte was readable by the program's students as soon as it existed, so its three
     *    parts become « visible dès maintenant » (the model's default, « masqué », only applies to
     *    the cahiers opened from now on);
     *  - an assignment was visible from its creation, so it is published as of the migration;
     *  - a day-grained deadline meant the end of that day, hence 23:59;
     *  - an attachment was only displayed on the work done, hence the « during » part.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assignment_completion (id INT AUTO_INCREMENT NOT NULL, done_at DATETIME NOT NULL, assignment_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_67553A1D19302F8 (assignment_id), INDEX IDX_67553A1CB944F1A (student_id), UNIQUE INDEX uniq_assignment_completion (assignment_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assignment_completion ADD CONSTRAINT FK_67553A1D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_completion ADD CONSTRAINT FK_67553A1CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE assignment ADD lesson_log_section VARCHAR(20) DEFAULT NULL, ADD accepted_formats JSON NOT NULL, ADD visible_at DATETIME DEFAULT NULL, ADD lesson_session_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA6C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BA6C36A50E ON assignment (lesson_session_id)');
        $this->addSql("UPDATE assignment SET accepted_formats = '[]', visible_at = NOW()");

        // The DATE -> DATETIME conversion would set 00:00: existing assignments would suddenly be due
        // on waking up rather than at the end of the day.
        $this->addSql('ALTER TABLE assignment CHANGE due_date due_date DATETIME NOT NULL');
        $this->addSql("UPDATE assignment SET due_date = DATE_ADD(DATE(due_date), INTERVAL '23:59' HOUR_MINUTE)");

        $this->addSql("ALTER TABLE lesson_log ADD visibility_before VARCHAR(20) NOT NULL DEFAULT 'now', ADD visibility_during VARCHAR(20) NOT NULL DEFAULT 'now', ADD visibility_after VARCHAR(20) NOT NULL DEFAULT 'now', ADD visible_at_before DATETIME DEFAULT NULL, ADD visible_at_during DATETIME DEFAULT NULL, ADD visible_at_after DATETIME DEFAULT NULL");
        $this->addSql('ALTER TABLE lesson_log ALTER visibility_before DROP DEFAULT, ALTER visibility_during DROP DEFAULT, ALTER visibility_after DROP DEFAULT');

        $this->addSql("ALTER TABLE lesson_log_attachment ADD section VARCHAR(20) NOT NULL DEFAULT 'during', ADD visible_at DATETIME DEFAULT NULL");
        $this->addSql('ALTER TABLE lesson_log_attachment ALTER section DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_completion DROP FOREIGN KEY FK_67553A1D19302F8');
        $this->addSql('ALTER TABLE assignment_completion DROP FOREIGN KEY FK_67553A1CB944F1A');
        $this->addSql('DROP TABLE assignment_completion');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA6C36A50E');
        $this->addSql('DROP INDEX IDX_30C544BA6C36A50E ON assignment');
        $this->addSql('ALTER TABLE assignment DROP lesson_log_section, DROP accepted_formats, DROP visible_at, DROP lesson_session_id, CHANGE due_date due_date DATE NOT NULL');
        $this->addSql('ALTER TABLE lesson_log DROP visibility_before, DROP visibility_during, DROP visibility_after, DROP visible_at_before, DROP visible_at_during, DROP visible_at_after');
        $this->addSql('ALTER TABLE lesson_log_attachment DROP section, DROP visible_at');
    }
}
