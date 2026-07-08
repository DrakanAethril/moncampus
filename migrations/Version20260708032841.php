<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708032841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow anonymous tickets: make ticket.reporter_id nullable and add reporter_name/reporter_contact for the logged-out "lost access" form.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket
            ADD
              reporter_name VARCHAR(255) DEFAULT NULL,
            ADD
              reporter_contact VARCHAR(255) DEFAULT NULL,
            CHANGE
              reporter_id reporter_id INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket
            DROP
              reporter_name,
            DROP
              reporter_contact,
            CHANGE
              reporter_id reporter_id INT NOT NULL
        SQL);
    }
}
