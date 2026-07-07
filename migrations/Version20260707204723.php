<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707204723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticket, ticket_category and ticket_comment tables for the support ticket feature.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket (
              id INT AUTO_INCREMENT NOT NULL,
              subject VARCHAR(255) NOT NULL,
              description LONGTEXT NOT NULL,
              other_location VARCHAR(255) DEFAULT NULL,
              status VARCHAR(255) NOT NULL,
              priority VARCHAR(255) NOT NULL,
              creation_date DATETIME NOT NULL,
              resolved_at DATETIME DEFAULT NULL,
              closed_at DATETIME DEFAULT NULL,
              category_id INT NOT NULL,
              room_id INT DEFAULT NULL,
              reporter_id INT NOT NULL,
              assignee_id INT DEFAULT NULL,
              INDEX IDX_97A0ADA312469DE2 (category_id),
              INDEX IDX_97A0ADA354177093 (room_id),
              INDEX IDX_97A0ADA3E1CFE6F5 (reporter_id),
              INDEX IDX_97A0ADA359EC7D60 (assignee_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_category (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              creation_date DATETIME NOT NULL,
              inactive_date DATETIME DEFAULT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              INDEX IDX_8325E540B03A8386 (created_by_id),
              INDEX IDX_8325E540F5A2E305 (inactivated_by_id),
              INDEX IDX_8325E540E562D849 (last_updated_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_comment (
              id INT AUTO_INCREMENT NOT NULL,
              body LONGTEXT NOT NULL,
              visibility VARCHAR(255) NOT NULL,
              is_system_generated TINYINT NOT NULL,
              creation_date DATETIME NOT NULL,
              ticket_id INT NOT NULL,
              author_id INT NOT NULL,
              INDEX IDX_98B80B3E700047D2 (ticket_id),
              INDEX IDX_98B80B3EF675F31B (author_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket
            ADD
              CONSTRAINT FK_97A0ADA312469DE2 FOREIGN KEY (category_id) REFERENCES ticket_category (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket
            ADD
              CONSTRAINT FK_97A0ADA354177093 FOREIGN KEY (room_id) REFERENCES room (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket
            ADD
              CONSTRAINT FK_97A0ADA3E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket
            ADD
              CONSTRAINT FK_97A0ADA359EC7D60 FOREIGN KEY (assignee_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket_category
            ADD
              CONSTRAINT FK_8325E540B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket_category
            ADD
              CONSTRAINT FK_8325E540F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket_category
            ADD
              CONSTRAINT FK_8325E540E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket_comment
            ADD
              CONSTRAINT FK_98B80B3E700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ticket_comment
            ADD
              CONSTRAINT FK_98B80B3EF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA312469DE2');
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA354177093');
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA3E1CFE6F5');
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA359EC7D60');
        $this->addSql('ALTER TABLE ticket_category DROP FOREIGN KEY FK_8325E540B03A8386');
        $this->addSql('ALTER TABLE ticket_category DROP FOREIGN KEY FK_8325E540F5A2E305');
        $this->addSql('ALTER TABLE ticket_category DROP FOREIGN KEY FK_8325E540E562D849');
        $this->addSql('ALTER TABLE ticket_comment DROP FOREIGN KEY FK_98B80B3E700047D2');
        $this->addSql('ALTER TABLE ticket_comment DROP FOREIGN KEY FK_98B80B3EF675F31B');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE ticket_category');
        $this->addSql('DROP TABLE ticket_comment');
    }
}
