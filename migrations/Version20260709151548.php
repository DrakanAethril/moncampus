<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709151548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extract InternshipTutorLink.companyName/companyAddress into a reusable Enterprise entity, and link the tutor account-creation request each link may have spawned.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE enterprise (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              address LONGTEXT DEFAULT NULL,
              creation_date DATETIME NOT NULL,
              inactive_date DATETIME DEFAULT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              INDEX IDX_B1B36A03B03A8386 (created_by_id),
              INDEX IDX_B1B36A03F5A2E305 (inactivated_by_id),
              INDEX IDX_B1B36A03E562D849 (last_updated_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              enterprise
            ADD
              CONSTRAINT FK_B1B36A03B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              enterprise
            ADD
              CONSTRAINT FK_B1B36A03F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              enterprise
            ADD
              CONSTRAINT FK_B1B36A03E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)
        SQL);

        // enterprise_id starts nullable so existing rows (if any) can be backfilled before it's
        // tightened to NOT NULL below - dev has zero internship_tutor_link rows today, but this
        // keeps the migration safe to run against a prod database that already has some.
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_tutor_link
            ADD
              ldap_manage_user_id INT UNSIGNED DEFAULT NULL,
            ADD
              enterprise_id INT DEFAULT NULL
        SQL);

        // One Enterprise per distinct company_name already on file; created_by/address are taken
        // from the oldest link naming that company, since company_address was only ever free text
        // per-link (not guaranteed identical across rows for the same company_name).
        $this->addSql(<<<'SQL'
            INSERT INTO enterprise (name, address, creation_date, created_by_id)
            SELECT company_name, MIN(company_address), NOW(), MIN(created_by_id)
            FROM internship_tutor_link
            GROUP BY company_name
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE internship_tutor_link l
            JOIN enterprise e ON e.name = l.company_name
            SET l.enterprise_id = e.id
        SQL);

        $this->addSql('ALTER TABLE internship_tutor_link MODIFY enterprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE internship_tutor_link DROP company_name, DROP company_address');

        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_tutor_link
            ADD
              CONSTRAINT FK_80D9578267AA68BD FOREIGN KEY (ldap_manage_user_id) REFERENCES ldap_manage_user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_tutor_link
            ADD
              CONSTRAINT FK_80D95782A97D1AC3 FOREIGN KEY (enterprise_id) REFERENCES enterprise (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_80D9578267AA68BD ON internship_tutor_link (ldap_manage_user_id)');
        $this->addSql('CREATE INDEX IDX_80D95782A97D1AC3 ON internship_tutor_link (enterprise_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE
              internship_tutor_link
            ADD
              company_name VARCHAR(255) DEFAULT NULL,
            ADD
              company_address LONGTEXT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE internship_tutor_link l
            JOIN enterprise e ON e.id = l.enterprise_id
            SET l.company_name = e.name, l.company_address = e.address
        SQL);
        $this->addSql('ALTER TABLE internship_tutor_link MODIFY company_name VARCHAR(255) NOT NULL');

        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D9578267AA68BD');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D95782A97D1AC3');
        $this->addSql('DROP INDEX IDX_80D9578267AA68BD ON internship_tutor_link');
        $this->addSql('DROP INDEX IDX_80D95782A97D1AC3 ON internship_tutor_link');
        $this->addSql('ALTER TABLE internship_tutor_link DROP ldap_manage_user_id, DROP enterprise_id');

        $this->addSql('ALTER TABLE enterprise DROP FOREIGN KEY FK_B1B36A03B03A8386');
        $this->addSql('ALTER TABLE enterprise DROP FOREIGN KEY FK_B1B36A03F5A2E305');
        $this->addSql('ALTER TABLE enterprise DROP FOREIGN KEY FK_B1B36A03E562D849');
        $this->addSql('DROP TABLE enterprise');
    }
}
