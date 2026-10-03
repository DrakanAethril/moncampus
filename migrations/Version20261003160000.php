<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tableau virtuel : photo du jour (fond Nature)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE class_board_photo (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, commons_title VARCHAR(255) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, author VARCHAR(255) NOT NULL, license_name VARCHAR(64) NOT NULL, license_url VARCHAR(255) DEFAULT NULL, source_url VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, INDEX class_board_photo_title_idx (commons_title), UNIQUE INDEX uniq_class_board_photo_day (day), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE class_board_photo');
    }
}
