<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260727125259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.pending_contact_email, a not-yet-confirmed address kept separate from contact_email.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD pending_contact_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP pending_contact_email');
    }
}
