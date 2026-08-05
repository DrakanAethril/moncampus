<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Read state of an inbound mail in the School mail box (screen 3b).
 *
 * It only covers what the student opened in their own mailbox: the inbox badge needs to know what
 * has not been read yet. Nothing here says anything about what the company does with what we send -
 * the handoff explicitly forbids any open tracking on the recipient's side (principle #1).
 */
final class Version20260805094000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds email_message.read_at: the student read state inside their School mail box.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_message ADD read_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_message DROP read_at');
    }
}
