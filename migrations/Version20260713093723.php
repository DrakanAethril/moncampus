<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713093723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact-email verification state to User (verified-at, pending token, token requested-at) and a uniqueness constraint on contact_email.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD contact_email_verified_at DATETIME DEFAULT NULL, ADD contact_email_token VARCHAR(64) DEFAULT NULL, ADD contact_email_token_requested_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649ACD65F0A ON user (contact_email_token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_contact_email ON user (contact_email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649ACD65F0A ON `user`');
        $this->addSql('DROP INDEX uniq_user_contact_email ON `user`');
        $this->addSql('ALTER TABLE `user` DROP contact_email_verified_at, DROP contact_email_token, DROP contact_email_token_requested_at');
    }
}
