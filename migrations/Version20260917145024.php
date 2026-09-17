<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quiz launched to one option of the class rather than to the whole class.
 */
final class Version20260917145024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds quiz_instance.visibility_option_id: the option a launched quiz is narrowed to.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance ADD visibility_option_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_instance ADD CONSTRAINT FK_94F4489BCE54F65B FOREIGN KEY (visibility_option_id) REFERENCES `option` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_94F4489BCE54F65B ON quiz_instance (visibility_option_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance DROP FOREIGN KEY FK_94F4489BCE54F65B');
        $this->addSql('DROP INDEX IDX_94F4489BCE54F65B ON quiz_instance');
        $this->addSql('ALTER TABLE quiz_instance DROP visibility_option_id');
    }
}
