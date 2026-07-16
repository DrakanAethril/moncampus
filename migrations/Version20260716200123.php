<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716200123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link ldap_manage_user rows to the User they belong to (nullable, backfilled for new rows only)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ldap_manage_user ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ldap_manage_user ADD CONSTRAINT FK_726A1193A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_726A1193A76ED395 ON ldap_manage_user (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ldap_manage_user DROP FOREIGN KEY FK_726A1193A76ED395');
        $this->addSql('DROP INDEX IDX_726A1193A76ED395 ON ldap_manage_user');
        $this->addSql('ALTER TABLE ldap_manage_user DROP user_id');
    }
}
