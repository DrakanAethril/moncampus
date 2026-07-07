<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707190038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add laptop and laptop_loan tables for the laptop lending feature.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE laptop (
              id INT AUTO_INCREMENT NOT NULL,
              asset_tag VARCHAR(255) NOT NULL,
              brand VARCHAR(255) DEFAULT NULL,
              model VARCHAR(255) DEFAULT NULL,
              notes LONGTEXT DEFAULT NULL,
              creation_date DATETIME NOT NULL,
              inactive_date DATETIME DEFAULT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              INDEX IDX_E001563BB03A8386 (created_by_id),
              INDEX IDX_E001563BF5A2E305 (inactivated_by_id),
              INDEX IDX_E001563BE562D849 (last_updated_by_id),
              UNIQUE INDEX uniq_laptop_asset_tag (asset_tag),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE laptop_loan (
              id INT AUTO_INCREMENT NOT NULL,
              lent_at DATETIME NOT NULL,
              due_at DATETIME NOT NULL,
              lent_state_notes LONGTEXT NOT NULL,
              returned_at DATETIME DEFAULT NULL,
              return_state_notes LONGTEXT DEFAULT NULL,
              return_condition VARCHAR(255) DEFAULT NULL,
              laptop_id INT NOT NULL,
              borrower_id INT NOT NULL,
              lent_by_id INT NOT NULL,
              returned_by_id INT DEFAULT NULL,
              INDEX IDX_FFDD3D2AD59905E5 (laptop_id),
              INDEX IDX_FFDD3D2A11CE312B (borrower_id),
              INDEX IDX_FFDD3D2A72FE63E9 (lent_by_id),
              INDEX IDX_FFDD3D2A71AD87D9 (returned_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop
            ADD
              CONSTRAINT FK_E001563BB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop
            ADD
              CONSTRAINT FK_E001563BF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop
            ADD
              CONSTRAINT FK_E001563BE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop_loan
            ADD
              CONSTRAINT FK_FFDD3D2AD59905E5 FOREIGN KEY (laptop_id) REFERENCES laptop (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop_loan
            ADD
              CONSTRAINT FK_FFDD3D2A11CE312B FOREIGN KEY (borrower_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop_loan
            ADD
              CONSTRAINT FK_FFDD3D2A72FE63E9 FOREIGN KEY (lent_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              laptop_loan
            ADD
              CONSTRAINT FK_FFDD3D2A71AD87D9 FOREIGN KEY (returned_by_id) REFERENCES `user` (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE laptop DROP FOREIGN KEY FK_E001563BB03A8386');
        $this->addSql('ALTER TABLE laptop DROP FOREIGN KEY FK_E001563BF5A2E305');
        $this->addSql('ALTER TABLE laptop DROP FOREIGN KEY FK_E001563BE562D849');
        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2AD59905E5');
        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2A11CE312B');
        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2A72FE63E9');
        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2A71AD87D9');
        $this->addSql('DROP TABLE laptop');
        $this->addSql('DROP TABLE laptop_loan');
    }
}
