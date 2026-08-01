<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801064936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the two timestamped activity journals (ufa_activity, platform_activity).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE platform_activity (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, type VARCHAR(60) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, payload JSON NOT NULL, actor_id INT DEFAULT NULL, INDEX IDX_2904094810DAF24A (actor_id), INDEX idx_platform_activity_occurred (occurred_at), INDEX idx_platform_activity_actor (actor_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ufa_activity (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, type VARCHAR(60) NOT NULL, test_data TINYINT DEFAULT 0 NOT NULL, payload JSON NOT NULL, actor_id INT DEFAULT NULL, tutor_link_id INT DEFAULT NULL, evaluation_period_id INT DEFAULT NULL, program_id INT DEFAULT NULL, INDEX IDX_25C55B2A10DAF24A (actor_id), INDEX IDX_25C55B2AC55C0FCE (tutor_link_id), INDEX IDX_25C55B2A3E8BB15A (evaluation_period_id), INDEX IDX_25C55B2A3EB8070A (program_id), INDEX idx_ufa_activity_occurred (occurred_at), INDEX idx_ufa_activity_tutor_link (tutor_link_id, occurred_at), INDEX idx_ufa_activity_program (program_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE platform_activity ADD CONSTRAINT FK_2904094810DAF24A FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ufa_activity ADD CONSTRAINT FK_25C55B2A10DAF24A FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ufa_activity ADD CONSTRAINT FK_25C55B2AC55C0FCE FOREIGN KEY (tutor_link_id) REFERENCES internship_tutor_link (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ufa_activity ADD CONSTRAINT FK_25C55B2A3E8BB15A FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ufa_activity ADD CONSTRAINT FK_25C55B2A3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_activity DROP FOREIGN KEY FK_2904094810DAF24A');
        $this->addSql('ALTER TABLE ufa_activity DROP FOREIGN KEY FK_25C55B2A10DAF24A');
        $this->addSql('ALTER TABLE ufa_activity DROP FOREIGN KEY FK_25C55B2AC55C0FCE');
        $this->addSql('ALTER TABLE ufa_activity DROP FOREIGN KEY FK_25C55B2A3E8BB15A');
        $this->addSql('ALTER TABLE ufa_activity DROP FOREIGN KEY FK_25C55B2A3EB8070A');
        $this->addSql('DROP TABLE platform_activity');
        $this->addSql('DROP TABLE ufa_activity');
    }
}
