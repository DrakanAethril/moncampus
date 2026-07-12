<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712094923 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assignment.option becomes a ManyToMany (assignment_option) instead of a single option';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE assignment_option (assignment_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_62A720ADD19302F8 (assignment_id), INDEX IDX_62A720ADA7C41D6F (option_id), PRIMARY KEY (assignment_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assignment_option ADD CONSTRAINT FK_62A720ADD19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_option ADD CONSTRAINT FK_62A720ADA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY `FK_30C544BAA7C41D6F`');
        $this->addSql('DROP INDEX IDX_30C544BAA7C41D6F ON assignment');
        $this->addSql('ALTER TABLE assignment DROP option_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assignment_option DROP FOREIGN KEY FK_62A720ADD19302F8');
        $this->addSql('ALTER TABLE assignment_option DROP FOREIGN KEY FK_62A720ADA7C41D6F');
        $this->addSql('DROP TABLE assignment_option');
        $this->addSql('ALTER TABLE assignment ADD option_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT `FK_30C544BAA7C41D6F` FOREIGN KEY (option_id) REFERENCES `option` (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_30C544BAA7C41D6F ON assignment (option_id)');
    }
}
