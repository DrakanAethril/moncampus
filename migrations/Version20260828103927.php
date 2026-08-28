<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The deployment notice: one row per production deploy, and the banner it puts on every screen
 * while it lasts. See App\Entity\DeploymentNotice for why this has to be a table.
 */
final class Version20260828103927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deployment notice: the banner shown while a production deploy is under way.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deployment_notice (id INT UNSIGNED AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, outcome VARCHAR(20) DEFAULT NULL, version VARCHAR(32) DEFAULT NULL, INDEX idx_deployment_notice_open (finished_at, expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE deployment_notice');
    }
}
