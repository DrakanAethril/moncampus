<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion > Matériel incidents: an incident line carries the count its pieces came from (`origin`)
 * and a `cause`; a « Retrouvé » or « Réparé » line points at the incident it answers (`resolves_id`).
 */
final class Version20260926074544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add origin, cause and resolved incident to equipment movements';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE equipment_movement ADD origin VARCHAR(20) DEFAULT NULL, ADD cause VARCHAR(20) DEFAULT NULL, ADD resolves_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE equipment_movement ADD CONSTRAINT FK_C41E11D11AD7FBCD FOREIGN KEY (resolves_id) REFERENCES equipment_movement (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_C41E11D11AD7FBCD ON equipment_movement (resolves_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE equipment_movement DROP FOREIGN KEY FK_C41E11D11AD7FBCD');
        $this->addSql('DROP INDEX IDX_C41E11D11AD7FBCD ON equipment_movement');
        $this->addSql('ALTER TABLE equipment_movement DROP origin, DROP cause, DROP resolves_id');
    }
}
