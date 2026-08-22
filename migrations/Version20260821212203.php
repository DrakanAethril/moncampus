<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les extraits de commande : la moitié personnelle de la palette.
 *
 * L'autre moitié - le catalogue de plateforme - n'est volontairement pas une table mais une classe
 * PHP (App\Console\ConsoleSnippetCatalog) : personne ne l'édite depuis un écran, il arrive par le
 * déploiement, comme le changelog.
 */
final class Version20260821212203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console des machines : les extraits de commande.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE console_snippet (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, command LONGTEXT NOT NULL, shared TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, use_count INT DEFAULT 0 NOT NULL, last_used_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, owner_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_606D6262B03A8386 (created_by_id), INDEX IDX_606D6262F5A2E305 (inactivated_by_id), INDEX IDX_606D6262E562D849 (last_updated_by_id), INDEX idx_console_snippet_owner (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE console_snippet ADD CONSTRAINT FK_606D62627E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE console_snippet ADD CONSTRAINT FK_606D6262B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE console_snippet ADD CONSTRAINT FK_606D6262F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE console_snippet ADD CONSTRAINT FK_606D6262E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE console_snippet DROP FOREIGN KEY FK_606D62627E3C61F9');
        $this->addSql('ALTER TABLE console_snippet DROP FOREIGN KEY FK_606D6262B03A8386');
        $this->addSql('ALTER TABLE console_snippet DROP FOREIGN KEY FK_606D6262F5A2E305');
        $this->addSql('ALTER TABLE console_snippet DROP FOREIGN KEY FK_606D6262E562D849');
        $this->addSql('DROP TABLE console_snippet');
    }
}
