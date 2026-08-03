<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803075705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Trace l'ouverture d'un document du cahier de texte par un étudiant.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lesson_log_attachment_view (id INT AUTO_INCREMENT NOT NULL, first_opened_at DATETIME NOT NULL, last_opened_at DATETIME NOT NULL, open_count INT NOT NULL, attachment_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_98C8CDF0464E68B (attachment_id), INDEX IDX_98C8CDF0CB944F1A (student_id), UNIQUE INDEX uniq_attachment_view (attachment_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lesson_log_attachment_view ADD CONSTRAINT FK_98C8CDF0464E68B FOREIGN KEY (attachment_id) REFERENCES lesson_log_attachment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson_log_attachment_view ADD CONSTRAINT FK_98C8CDF0CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson_log_attachment_view DROP FOREIGN KEY FK_98C8CDF0464E68B');
        $this->addSql('ALTER TABLE lesson_log_attachment_view DROP FOREIGN KEY FK_98C8CDF0CB944F1A');
        $this->addSql('DROP TABLE lesson_log_attachment_view');
    }
}
