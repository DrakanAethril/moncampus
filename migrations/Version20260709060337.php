<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709060337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lesson_session.length (manually entered hours, used by financial reporting instead of computing from start/end).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson_session ADD length NUMERIC(10, 2) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson_session DROP length');
    }
}
