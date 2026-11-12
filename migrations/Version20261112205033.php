<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM: a formation no longer asks for « an option » as a whole, it names the options it follows
 * apart. A formation that asked for the option keeps every option it has today.
 */
final class Version20261112205033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM: the options a formation follows apart replace the single « option asked » switch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_program ADD tracked_option_ids JSON DEFAULT NULL');
        $this->addSql('UPDATE crm_program c SET c.tracked_option_ids = IF(c.option_required = 1, COALESCE((SELECT JSON_ARRAYAGG(op.option_id) FROM option_program op WHERE op.program_id = c.program_id), JSON_ARRAY()), JSON_ARRAY())');
        $this->addSql('ALTER TABLE crm_program MODIFY tracked_option_ids JSON NOT NULL, DROP option_required');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_program ADD option_required TINYINT NOT NULL DEFAULT 0');
        $this->addSql('UPDATE crm_program SET option_required = IF(JSON_LENGTH(tracked_option_ids) > 0, 1, 0)');
        $this->addSql('ALTER TABLE crm_program ALTER option_required DROP DEFAULT, DROP tracked_option_ids');
    }
}
