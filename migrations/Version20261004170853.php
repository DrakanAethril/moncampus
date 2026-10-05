<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ECF booklet: the titre is no longer typed in the ECF tab. Code titre and millésime move to the
 * certification (« Dénomination »), carried over for the formations that had them; label, sigle and
 * level are read from what « Dénomination » already holds.
 */
final class Version20261004170853 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ECF booklet: code titre and millésime move to the certification';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program_certification ADD title_code VARCHAR(30) DEFAULT NULL, ADD millesime VARCHAR(10) DEFAULT NULL');
        $this->addSql('UPDATE program_certification c INNER JOIN program_ecf_settings e ON e.program_id = c.program_id SET c.title_code = e.title_code, c.millesime = e.millesime');
        $this->addSql('ALTER TABLE program_ecf_settings DROP title_label, DROP sigle, DROP level, DROP title_code, DROP millesime');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program_certification DROP title_code, DROP millesime');
        $this->addSql('ALTER TABLE program_ecf_settings ADD title_label VARCHAR(255) DEFAULT NULL, ADD sigle VARCHAR(20) DEFAULT NULL, ADD level VARCHAR(10) DEFAULT NULL, ADD title_code VARCHAR(30) DEFAULT NULL, ADD millesime VARCHAR(10) DEFAULT NULL');
    }
}
