<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * e-CO: what the IGN's Géoplateforme says about the ground - the terrain altitude and vegetation
 * height under each flag, the parcours' terrain analysis, and per GPS fix the terrain altitude,
 * path and wood flags that app:eco:read-terrain writes after a race.
 */
final class Version20260928091318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'e-CO: IGN terrain readings on checkpoints, parcours and position pings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_checkpoint ADD ground_altitude DOUBLE PRECISION DEFAULT NULL, ADD canopy_height DOUBLE PRECISION DEFAULT NULL, ADD terrain_read_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE eco_parcours ADD terrain_analysis JSON DEFAULT NULL, ADD terrain_analyzed_at DATETIME DEFAULT NULL, ADD terrain_requested_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE eco_position_ping ADD ground_altitude DOUBLE PRECISION DEFAULT NULL, ADD on_path TINYINT DEFAULT NULL, ADD in_forest TINYINT DEFAULT NULL, ADD terrain_resolved TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX eco_position_ping_terrain_idx ON eco_position_ping (terrain_resolved, runner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_checkpoint DROP ground_altitude, DROP canopy_height, DROP terrain_read_at');
        $this->addSql('ALTER TABLE eco_parcours DROP terrain_analysis, DROP terrain_analyzed_at, DROP terrain_requested_at');
        $this->addSql('DROP INDEX eco_position_ping_terrain_idx ON eco_position_ping');
        $this->addSql('ALTER TABLE eco_position_ping DROP ground_altitude, DROP on_path, DROP in_forest, DROP terrain_resolved');
    }
}
