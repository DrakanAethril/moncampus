<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The virtual board (design/validated/tableau-virtuel.md): one row per board, its widgets in one
 * JSON document, the revision that keeps two tabs from erasing each other.
 */
final class Version20261001162747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Class board: the virtual board for the projector';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE class_board (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, background VARCHAR(20) NOT NULL, layout JSON NOT NULL, layout_version INT NOT NULL, revision INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, program_id INT DEFAULT NULL, INDEX IDX_BE810A717E3C61F9 (owner_id), INDEX IDX_BE810A713EB8070A (program_id), UNIQUE INDEX uniq_class_board_owner_name (owner_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE class_board ADD CONSTRAINT FK_BE810A717E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_board ADD CONSTRAINT FK_BE810A713EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE class_board DROP FOREIGN KEY FK_BE810A717E3C61F9');
        $this->addSql('ALTER TABLE class_board DROP FOREIGN KEY FK_BE810A713EB8070A');
        $this->addSql('DROP TABLE class_board');
    }
}
