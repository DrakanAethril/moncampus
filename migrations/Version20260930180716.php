<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930180716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'e-CO : partage des parcours entre enseignants e-CO, course « Balises spécifiques ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE eco_course_checkpoint (course_id INT NOT NULL, checkpoint_id INT NOT NULL, INDEX IDX_7CEC5B32591CC992 (course_id), INDEX IDX_7CEC5B32F27C615F (checkpoint_id), PRIMARY KEY (course_id, checkpoint_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_parcours_share (parcours_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_6C73ADF76E38C0DB (parcours_id), INDEX IDX_6C73ADF7A76ED395 (user_id), PRIMARY KEY (parcours_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE eco_course_checkpoint ADD CONSTRAINT FK_7CEC5B32591CC992 FOREIGN KEY (course_id) REFERENCES eco_course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_course_checkpoint ADD CONSTRAINT FK_7CEC5B32F27C615F FOREIGN KEY (checkpoint_id) REFERENCES eco_checkpoint (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_parcours_share ADD CONSTRAINT FK_6C73ADF76E38C0DB FOREIGN KEY (parcours_id) REFERENCES eco_parcours (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_parcours_share ADD CONSTRAINT FK_6C73ADF7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eco_course ADD specific_ordered TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_course_checkpoint DROP FOREIGN KEY FK_7CEC5B32591CC992');
        $this->addSql('ALTER TABLE eco_course_checkpoint DROP FOREIGN KEY FK_7CEC5B32F27C615F');
        $this->addSql('ALTER TABLE eco_parcours_share DROP FOREIGN KEY FK_6C73ADF76E38C0DB');
        $this->addSql('ALTER TABLE eco_parcours_share DROP FOREIGN KEY FK_6C73ADF7A76ED395');
        $this->addSql('DROP TABLE eco_course_checkpoint');
        $this->addSql('DROP TABLE eco_parcours_share');
        $this->addSql('ALTER TABLE eco_course DROP specific_ordered');
    }
}
