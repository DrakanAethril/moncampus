<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Plans de classe » (design/validated/plans-de-classe.md): the plans, the colleagues they are
 * shared with, the disposition drawn once per room of the timetable - and the accommodation
 * criterion « placer devant » the plans propose a rule from.
 */
final class Version20261110103419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seating plans, room layouts, and the seat-in-front accommodation criterion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE seating_plan (id INT AUTO_INCREMENT NOT NULL, room_name VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, document JSON NOT NULL, revision INT NOT NULL, shared_can_edit TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, program_id INT NOT NULL, room_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_DD693D377E3C61F9 (owner_id), INDEX IDX_DD693D373EB8070A (program_id), INDEX IDX_DD693D3754177093 (room_id), INDEX IDX_DD693D37896DBBDE (updated_by_id), INDEX idx_seating_plan_owner_updated (owner_id, updated_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seating_plan_shared_teacher (seating_plan_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_4DA7897D19E3A7BA (seating_plan_id), INDEX IDX_4DA7897DA76ED395 (user_id), PRIMARY KEY (seating_plan_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seating_room_layout (id INT AUTO_INCREMENT NOT NULL, layout JSON NOT NULL, updated_at DATETIME NOT NULL, room_id INT NOT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_A323A905896DBBDE (updated_by_id), UNIQUE INDEX uniq_seating_room_layout_room (room_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE seating_plan ADD CONSTRAINT FK_DD693D377E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seating_plan ADD CONSTRAINT FK_DD693D373EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seating_plan ADD CONSTRAINT FK_DD693D3754177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE seating_plan ADD CONSTRAINT FK_DD693D37896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE seating_plan_shared_teacher ADD CONSTRAINT FK_4DA7897D19E3A7BA FOREIGN KEY (seating_plan_id) REFERENCES seating_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seating_plan_shared_teacher ADD CONSTRAINT FK_4DA7897DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seating_room_layout ADD CONSTRAINT FK_A323A90554177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seating_room_layout ADD CONSTRAINT FK_A323A905896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE accommodation ADD seat_in_front TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seating_plan DROP FOREIGN KEY FK_DD693D377E3C61F9');
        $this->addSql('ALTER TABLE seating_plan DROP FOREIGN KEY FK_DD693D373EB8070A');
        $this->addSql('ALTER TABLE seating_plan DROP FOREIGN KEY FK_DD693D3754177093');
        $this->addSql('ALTER TABLE seating_plan DROP FOREIGN KEY FK_DD693D37896DBBDE');
        $this->addSql('ALTER TABLE seating_plan_shared_teacher DROP FOREIGN KEY FK_4DA7897D19E3A7BA');
        $this->addSql('ALTER TABLE seating_plan_shared_teacher DROP FOREIGN KEY FK_4DA7897DA76ED395');
        $this->addSql('ALTER TABLE seating_room_layout DROP FOREIGN KEY FK_A323A90554177093');
        $this->addSql('ALTER TABLE seating_room_layout DROP FOREIGN KEY FK_A323A905896DBBDE');
        $this->addSql('DROP TABLE seating_plan');
        $this->addSql('DROP TABLE seating_plan_shared_teacher');
        $this->addSql('DROP TABLE seating_room_layout');
        $this->addSql('ALTER TABLE accommodation DROP seat_in_front');
    }
}
