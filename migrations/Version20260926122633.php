<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Part du quiz » on the launch screen: the share of the draw each merged quiz provides, frozen on
 * the instance, and which quiz of the launch each copied question came from. Existing instances
 * keep a null share and position 0, which is the single merged pool they were drawn from.
 */
final class Version20260926122633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store per-quiz shares of a merged quiz launch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance ADD pool_shares JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question ADD pool_index SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance DROP pool_shares');
        $this->addSql('ALTER TABLE quiz_instance_question DROP pool_index');
    }
}
