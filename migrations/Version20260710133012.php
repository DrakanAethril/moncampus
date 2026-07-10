<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710133012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add topic_group (groups a Program\'s Topics) and the required topic.topic_group_id relation - every Topic must belong to exactly one group.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE topic_group (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              creation_date DATETIME NOT NULL,
              inactive_date DATETIME DEFAULT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              program_id INT NOT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              INDEX IDX_D6F3E8A73EB8070A (program_id),
              INDEX IDX_D6F3E8A7B03A8386 (created_by_id),
              INDEX IDX_D6F3E8A7F5A2E305 (inactivated_by_id),
              INDEX IDX_D6F3E8A7E562D849 (last_updated_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              topic_group
            ADD
              CONSTRAINT FK_D6F3E8A73EB8070A FOREIGN KEY (program_id) REFERENCES program (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              topic_group
            ADD
              CONSTRAINT FK_D6F3E8A7B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              topic_group
            ADD
              CONSTRAINT FK_D6F3E8A7F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              topic_group
            ADD
              CONSTRAINT FK_D6F3E8A7E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql('ALTER TABLE topic ADD topic_group_id INT NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              topic
            ADD
              CONSTRAINT FK_9D40DE1B8655441 FOREIGN KEY (topic_group_id) REFERENCES topic_group (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_9D40DE1B8655441 ON topic (topic_group_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE topic_group DROP FOREIGN KEY FK_D6F3E8A73EB8070A');
        $this->addSql('ALTER TABLE topic_group DROP FOREIGN KEY FK_D6F3E8A7B03A8386');
        $this->addSql('ALTER TABLE topic_group DROP FOREIGN KEY FK_D6F3E8A7F5A2E305');
        $this->addSql('ALTER TABLE topic_group DROP FOREIGN KEY FK_D6F3E8A7E562D849');
        $this->addSql('DROP TABLE topic_group');
        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY FK_9D40DE1B8655441');
        $this->addSql('DROP INDEX IDX_9D40DE1B8655441 ON topic');
        $this->addSql('ALTER TABLE topic DROP topic_group_id');
    }
}
