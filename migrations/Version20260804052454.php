<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The « Correction: … » box of screen 1m (design_handoff_quiz): an optional explanation per question,
 * shown to the student in a practice correction when they got the question wrong.
 *
 * Frozen at launch like the rest of the question, hence the column on both tables: editing the
 * template's explanation must change nothing to the quizzes already launched.
 */
final class Version20260804052454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Explication facultative par question, affichée en correction d'entraînement";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_instance_question ADD explanation LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_question ADD explanation LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_instance_question DROP explanation');
        $this->addSql('ALTER TABLE quiz_question DROP explanation');
    }
}
