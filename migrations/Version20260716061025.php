<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716061025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Period can now be associated with one or more Modality (join table modality_period)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE modality_period (modality_id INT NOT NULL, period_id INT NOT NULL, INDEX IDX_69C8A6C2D6D889B (modality_id), INDEX IDX_69C8A6CEC8B7ADE (period_id), PRIMARY KEY (modality_id, period_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE modality_period ADD CONSTRAINT FK_69C8A6C2D6D889B FOREIGN KEY (modality_id) REFERENCES modality (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_period ADD CONSTRAINT FK_69C8A6CEC8B7ADE FOREIGN KEY (period_id) REFERENCES period (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE modality_period DROP FOREIGN KEY FK_69C8A6C2D6D889B');
        $this->addSql('ALTER TABLE modality_period DROP FOREIGN KEY FK_69C8A6CEC8B7ADE');
        $this->addSql('DROP TABLE modality_period');
    }
}
