<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conditions d'accès (point 3) : deux colonnes sur les quatre hôtes.
 *
 * `access_condition` est nullable et null vaut « aucune condition », donc aucune ligne existante
 * n'est réécrite et rien ne change pour ce qui est déjà en base. `access_condition_display` porte
 * son défaut « locked » côté MySQL comme côté entité : c'est le côté sûr, un grisé injustifié se
 * voit là où un masquage injustifié fait disparaître un travail sans diagnostic.
 */
final class Version20260812082828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conditions d\'accès : colonnes access_condition et access_condition_display sur assignment, library_resource_instance, quiz_instance et sequence_instance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
        $this->addSql('ALTER TABLE library_resource_instance ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
        $this->addSql('ALTER TABLE quiz_instance ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
        $this->addSql('ALTER TABLE sequence_instance ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment DROP access_condition, DROP access_condition_display');
        $this->addSql('ALTER TABLE library_resource_instance DROP access_condition, DROP access_condition_display');
        $this->addSql('ALTER TABLE quiz_instance DROP access_condition, DROP access_condition_display');
        $this->addSql('ALTER TABLE sequence_instance DROP access_condition, DROP access_condition_display');
    }
}
