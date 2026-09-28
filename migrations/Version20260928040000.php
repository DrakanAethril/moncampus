<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Symfony Messenger is gone (symfony/doctrine-messenger removed): mail and notifier messages were
 * all routed to sync://, so the Doctrine transport's table only ever held the messages queued to
 * `async` before 2026-07-11, which no worker was ever going to send. Without the transport, nothing
 * maps the table any more and doctrine:schema:validate would report it as drift.
 */
final class Version20260928040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop messenger_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }
}
