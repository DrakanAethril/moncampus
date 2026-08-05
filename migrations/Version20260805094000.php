<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * État de lecture d'un mail entrant dans la boîte Courrier école (écran 3b).
 *
 * Ne concerne que ce que l'élève a ouvert dans sa propre boîte : la pastille de la réception a
 * besoin de savoir ce qui n'a pas encore été lu. Rien ici ne dit quoi que ce soit de ce que
 * l'entreprise fait des envois - le handoff interdit explicitement toute détection d'ouverture
 * côté destinataire (principe n°1).
 */
final class Version20260805094000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute email_message.read_at : l'état de lecture de l'élève dans sa boîte Courrier école.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_message ADD read_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_message DROP read_at');
    }
}
