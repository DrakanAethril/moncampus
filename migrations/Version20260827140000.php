<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reconnaissance - design/validated/gamification.md, lot 4.
 *
 * `class_council_mention` is **useful well beyond the game**, which is why it is a table of its own
 * rather than a ledger line: the mention belongs on a bulletin, in a livret and in a student's
 * history, and it stays true whether or not the formation ever plays. One row per student and per
 * period, and the unique index says so.
 *
 * `game_entry.gesture_object` is the closed list of §4, decision 6: a malus bears on dress or on
 * behaviour and on nothing else. A column rather than a prefix on the reason, because the reason is
 * read by the student exactly as it was typed, and because a closed list in the schema is what stops
 * the gesture from acquiring a third subject one screen at a time.
 */
final class Version20260827140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Class council mentions, and the subject of the single malus';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE class_council_mention (id INT AUTO_INCREMENT NOT NULL, mention VARCHAR(20) NOT NULL, comment LONGTEXT DEFAULT NULL, locked_at DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, student_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_955257ECB944F1A (student_id), INDEX IDX_955257E3EB8070A (program_id), INDEX IDX_955257EEC8B7ADE (period_id), INDEX IDX_955257EB03A8386 (created_by_id), INDEX IDX_955257EF5A2E305 (inactivated_by_id), INDEX IDX_955257EE562D849 (last_updated_by_id), UNIQUE INDEX uniq_class_council_mention (student_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE class_council_mention ADD CONSTRAINT FK_955257ECB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_council_mention ADD CONSTRAINT FK_955257E3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_council_mention ADD CONSTRAINT FK_955257EEC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_council_mention ADD CONSTRAINT FK_955257EB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE class_council_mention ADD CONSTRAINT FK_955257EF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE class_council_mention ADD CONSTRAINT FK_955257EE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE game_entry ADD gesture_object VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_entry DROP gesture_object');
        $this->addSql('DROP TABLE class_council_mention');
    }
}
