<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cours en ligne : quiz de test lié à un cours';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_course ADD quiz_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE online_course ADD CONSTRAINT FK_EEC37E172AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EEC37E172AFC1C18 ON online_course (quiz_template_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_course DROP FOREIGN KEY FK_EEC37E172AFC1C18');
        $this->addSql('DROP INDEX IDX_EEC37E172AFC1C18 ON online_course');
        $this->addSql('ALTER TABLE online_course DROP quiz_template_id');
    }
}
