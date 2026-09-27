<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927105132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mots de passe propres aux services externes (connecteur Claude)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE external_service_password (id INT AUTO_INCREMENT NOT NULL, service VARCHAR(40) NOT NULL, password_hash VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_D7FCC0BDA76ED395 (user_id), UNIQUE INDEX uniq_external_service_password_user_service (user_id, service), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE external_service_password ADD CONSTRAINT FK_D7FCC0BDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE external_service_password DROP FOREIGN KEY FK_D7FCC0BDA76ED395');
        $this->addSql('DROP TABLE external_service_password');
    }
}
