<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717044041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Program.testProgram, flagging demo/throwaway Programs so their data can be told apart from real data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program ADD test_program TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program DROP test_program');
    }
}
