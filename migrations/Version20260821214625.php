<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La diffusion : une ligne, toutes les machines d'un lot.
 *
 * `console_broadcast` porte la seule chose faite dans une console qui a un effet *ailleurs*, avec un
 * résultat par machine - une machine éteinte n'est pas un échec de la diffusion, c'est une machine
 * éteinte, et elle est nommée comme telle.
 *
 * `console_session.broadcast_armed_at` porte l'armement : jamais par défaut, et il se relâche tout
 * seul au bout de dix minutes sans envoi.
 */
final class Version20260821214625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console des machines : la diffusion à tout le lot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE console_broadcast (id INT AUTO_INCREMENT NOT NULL, batch_label VARCHAR(120) DEFAULT NULL, command LONGTEXT NOT NULL, sent_at DATETIME NOT NULL, results JSON NOT NULL, session_id INT NOT NULL, batch_id INT DEFAULT NULL, sent_by_id INT NOT NULL, INDEX IDX_77C77D84F39EBE7A (batch_id), INDEX IDX_77C77D84A45BB98C (sent_by_id), INDEX idx_console_broadcast_session (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE console_broadcast ADD CONSTRAINT FK_77C77D84613FECDF FOREIGN KEY (session_id) REFERENCES console_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE console_broadcast ADD CONSTRAINT FK_77C77D84F39EBE7A FOREIGN KEY (batch_id) REFERENCES vm_batch (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE console_broadcast ADD CONSTRAINT FK_77C77D84A45BB98C FOREIGN KEY (sent_by_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE console_session ADD broadcast_armed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE console_broadcast DROP FOREIGN KEY FK_77C77D84613FECDF');
        $this->addSql('ALTER TABLE console_broadcast DROP FOREIGN KEY FK_77C77D84F39EBE7A');
        $this->addSql('ALTER TABLE console_broadcast DROP FOREIGN KEY FK_77C77D84A45BB98C');
        $this->addSql('DROP TABLE console_broadcast');
        $this->addSql('ALTER TABLE console_session DROP broadcast_armed_at');
    }
}
