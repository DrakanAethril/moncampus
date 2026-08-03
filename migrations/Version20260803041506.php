<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803041506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Rattache un travail de cahier de texte au quiz qu'il demande de dérouler.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment ADD quiz_instance_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA157761BD FOREIGN KEY (quiz_instance_id) REFERENCES quiz_instance (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BA157761BD ON assignment (quiz_instance_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA157761BD');
        $this->addSql('DROP INDEX IDX_30C544BA157761BD ON assignment');
        $this->addSql('ALTER TABLE assignment DROP quiz_instance_id');
    }
}
