<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stores the identifier SES assigns to an outgoing school mail.
 *
 * **SES rewrites the Message-ID header.** Whatever the application sets is replaced on send; the
 * recipient sees `<{ses-id}@{region}.amazonses.com>`, and the delivery events published on the
 * "events" queue speak of the bare `{ses-id}`. Proven by looping a mail through our own catch-all
 * domain: sent as `<2c8588...@devetu.beaupeyrat.org>`, received as
 * `<0113019fd068b7fc-...-000000@eu-west-3.amazonses.com>`.
 *
 * Correlating on our own identifier therefore matched nothing - neither a delivery event, nor a
 * reply, whose In-Reply-To carries what the recipient saw. This column is that identifier, and
 * `message_id` now holds the header as it really travelled.
 */
final class Version20260805190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds email_message.provider_message_id: the identifier SES assigns, which events and replies refer to.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_message ADD provider_message_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_email_message_provider_message_id ON email_message (provider_message_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_email_message_provider_message_id ON email_message');
        $this->addSql('ALTER TABLE email_message DROP provider_message_id');
    }
}
