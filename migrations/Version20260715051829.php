<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715051829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace LaptopLoan::RETURN_CONDITIONS (a hard-coded string enum) with a manageable LaptopConditionType lookup (name+color), and add the same condition to the lend side (lentConditionType), not just return.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE laptop_condition_type (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              color VARCHAR(20) NOT NULL,
              creation_date DATETIME NOT NULL,
              inactive_date DATETIME DEFAULT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              INDEX IDX_BACA02EEB03A8386 (created_by_id),
              INDEX IDX_BACA02EEF5A2E305 (inactivated_by_id),
              INDEX IDX_BACA02EEE562D849 (last_updated_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE laptop_condition_type ADD CONSTRAINT FK_BACA02EEB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE laptop_condition_type ADD CONSTRAINT FK_BACA02EEF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE laptop_condition_type ADD CONSTRAINT FK_BACA02EEE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');

        // Seed the 3 conditions the app used to hard-code (LaptopLoan::RETURN_CONDITIONS, now
        // removed) as real, manageable rows - colors match the semantic categories already
        // established for .cm-actions row-action colors (positive/warning/danger), see
        // assets/styles/app.css. created_by_id has no real "system" user to attribute to, so
        // (like Version20260709151548's enterprise backfill) falls back to the oldest account.
        $this->addSql(<<<'SQL'
            INSERT INTO laptop_condition_type (name, color, creation_date, created_by_id)
            SELECT 'Bon état', '#2e7d4f', NOW(), MIN(id) FROM `user`
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO laptop_condition_type (name, color, creation_date, created_by_id)
            SELECT 'Endommagé', '#b0722a', NOW(), MIN(id) FROM `user`
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO laptop_condition_type (name, color, creation_date, created_by_id)
            SELECT 'Perdu', '#a43e2e', NOW(), MIN(id) FROM `user`
        SQL);

        // Both new laptop_loan columns start nullable so existing rows can be backfilled before
        // lent_condition_type_id is tightened to NOT NULL below - same "safe against a database
        // that already has rows" reasoning as Version20260709151548's enterprise_id.
        $this->addSql('ALTER TABLE laptop_loan ADD lent_condition_type_id INT DEFAULT NULL, ADD return_condition_type_id INT DEFAULT NULL');

        // Existing rows never recorded a lend-time condition (the field is new) - default them
        // all to "Bon état" rather than leaving history with an impossible NULL once the column
        // is required.
        $this->addSql(<<<'SQL'
            UPDATE laptop_loan
            SET lent_condition_type_id = (SELECT id FROM laptop_condition_type WHERE name = 'Bon état')
        SQL);

        // Map the old return_condition string values onto the new rows.
        $this->addSql(<<<'SQL'
            UPDATE laptop_loan
            SET return_condition_type_id = (SELECT id FROM laptop_condition_type WHERE name = 'Bon état')
            WHERE return_condition = 'ok'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE laptop_loan
            SET return_condition_type_id = (SELECT id FROM laptop_condition_type WHERE name = 'Endommagé')
            WHERE return_condition = 'damaged'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE laptop_loan
            SET return_condition_type_id = (SELECT id FROM laptop_condition_type WHERE name = 'Perdu')
            WHERE return_condition = 'lost'
        SQL);

        $this->addSql('ALTER TABLE laptop_loan MODIFY lent_condition_type_id INT NOT NULL');
        $this->addSql('ALTER TABLE laptop_loan DROP return_condition');
        $this->addSql('ALTER TABLE laptop_loan ADD CONSTRAINT FK_FFDD3D2A6C2B0B09 FOREIGN KEY (lent_condition_type_id) REFERENCES laptop_condition_type (id)');
        $this->addSql('ALTER TABLE laptop_loan ADD CONSTRAINT FK_FFDD3D2A836E51C6 FOREIGN KEY (return_condition_type_id) REFERENCES laptop_condition_type (id)');
        $this->addSql('CREATE INDEX IDX_FFDD3D2A6C2B0B09 ON laptop_loan (lent_condition_type_id)');
        $this->addSql('CREATE INDEX IDX_FFDD3D2A836E51C6 ON laptop_loan (return_condition_type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop_loan ADD return_condition VARCHAR(255) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE laptop_loan loan
            JOIN laptop_condition_type ct ON ct.id = loan.return_condition_type_id
            SET loan.return_condition = CASE ct.name
                WHEN 'Bon état' THEN 'ok'
                WHEN 'Endommagé' THEN 'damaged'
                WHEN 'Perdu' THEN 'lost'
                ELSE NULL
            END
        SQL);

        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2A6C2B0B09');
        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2A836E51C6');
        $this->addSql('DROP INDEX IDX_FFDD3D2A6C2B0B09 ON laptop_loan');
        $this->addSql('DROP INDEX IDX_FFDD3D2A836E51C6 ON laptop_loan');
        $this->addSql('ALTER TABLE laptop_loan DROP lent_condition_type_id, DROP return_condition_type_id');

        $this->addSql('ALTER TABLE laptop_condition_type DROP FOREIGN KEY FK_BACA02EEB03A8386');
        $this->addSql('ALTER TABLE laptop_condition_type DROP FOREIGN KEY FK_BACA02EEF5A2E305');
        $this->addSql('ALTER TABLE laptop_condition_type DROP FOREIGN KEY FK_BACA02EEE562D849');
        $this->addSql('DROP TABLE laptop_condition_type');
    }
}
