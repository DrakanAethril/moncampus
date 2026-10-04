<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The ministry's bloc-marque printed on the ECF booklet's cover, uploaded in UFA > Configuration.
 */
final class Version20261004090447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ECF booklet: the ministry logo key on the training centre';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_formation_center ADD ecf_ministry_logo_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_formation_center DROP ecf_ministry_logo_key');
    }
}
