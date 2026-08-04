<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encart « Correction : … » de l'écran 1m (design_handoff_quiz) : une explication facultative par
 * question, affichée à l'étudiant en correction d'entraînement quand il a raté la question.
 *
 * Figée au lancement comme le reste de la question, d'où la colonne sur les deux tables : modifier
 * l'explication du modèle ne doit rien changer aux quiz déjà lancés.
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
