<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One sequence instantiation per (source template, program): the rule the screen enforces, stated in the schema too';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_sequence_instance_template_program ON sequence_instance (source_template_id, program_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_sequence_instance_template_program ON sequence_instance');
    }
}
