<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cours en ligne : bandeau de la page d\'un enseignant (couleurs, image, hauteur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE online_course_page ADD banner_color VARCHAR(7) DEFAULT '#12344d' NOT NULL, ADD title_color VARCHAR(7) DEFAULT '#ffffff' NOT NULL, ADD banner_image_key VARCHAR(255) DEFAULT NULL, ADD banner_height INT DEFAULT 230 NOT NULL");
        $this->addSql('ALTER TABLE online_course_page ALTER banner_color DROP DEFAULT, ALTER title_color DROP DEFAULT, ALTER banner_height DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_course_page DROP banner_color, DROP title_color, DROP banner_image_key, DROP banner_height');
    }
}
