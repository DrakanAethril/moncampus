<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712164443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE topic_group_option (topic_group_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_D7055FF48655441 (topic_group_id), INDEX IDX_D7055FF4A7C41D6F (option_id), PRIMARY KEY (topic_group_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE topic_group_option ADD CONSTRAINT FK_D7055FF48655441 FOREIGN KEY (topic_group_id) REFERENCES topic_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE topic_group_option ADD CONSTRAINT FK_D7055FF4A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE topic_group_option DROP FOREIGN KEY FK_D7055FF48655441');
        $this->addSql('ALTER TABLE topic_group_option DROP FOREIGN KEY FK_D7055FF4A7C41D6F');
        $this->addSql('DROP TABLE topic_group_option');
    }
}
