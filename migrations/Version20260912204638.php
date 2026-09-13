<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jobboard: the five tables of design/validated/jobboard.md - the offers themselves, the ingestion
 * keys, the batches and their idempotency records, and the collection cursors.
 */
final class Version20260912204638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jobboard: offres, jetons d\'ingestion, lots et curseurs de collecte';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jobboard_batch (id INT AUTO_INCREMENT NOT NULL, opened_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, created_count INT NOT NULL, reviewed_count INT NOT NULL, rejected_count INT NOT NULL, token_id INT DEFAULT NULL, imported_by_id INT DEFAULT NULL, section_id INT NOT NULL, INDEX IDX_538AD59A41DEE7B9 (token_id), INDEX IDX_538AD59A74953CEA (imported_by_id), INDEX IDX_538AD59AD823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jobboard_batch_request (id INT AUTO_INCREMENT NOT NULL, idempotency_key VARCHAR(190) NOT NULL, payload_hash VARCHAR(64) NOT NULL, response JSON NOT NULL, created_at DATETIME NOT NULL, batch_id INT NOT NULL, INDEX IDX_C8FC689CF39EBE7A (batch_id), UNIQUE INDEX uniq_jobboard_batch_request_key (batch_id, idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jobboard_cursor (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(32) NOT NULL, search VARCHAR(190) NOT NULL, last_ref VARCHAR(190) DEFAULT NULL, last_published_at DATE DEFAULT NULL, updated_at DATETIME NOT NULL, section_id INT NOT NULL, INDEX IDX_54B0879ED823E37A (section_id), UNIQUE INDEX uniq_jobboard_cursor_scope (section_id, source, search), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jobboard_offer (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(32) NOT NULL, source_ref VARCHAR(190) NOT NULL, url VARCHAR(1000) NOT NULL, position VARCHAR(255) NOT NULL, company VARCHAR(255) NOT NULL, category VARCHAR(32) DEFAULT NULL, contract VARCHAR(16) NOT NULL, country VARCHAR(16) NOT NULL, region VARCHAR(120) DEFAULT NULL, departement VARCHAR(3) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, level VARCHAR(120) NOT NULL, level_source VARCHAR(16) NOT NULL, bts_access VARCHAR(16) NOT NULL, remote VARCHAR(16) NOT NULL, published_at DATE DEFAULT NULL, published_at_approx TINYINT NOT NULL, sort_date DATE NOT NULL, note VARCHAR(500) DEFAULT NULL, raw JSON DEFAULT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, section_id INT NOT NULL, canonical_id INT DEFAULT NULL, INDEX IDX_82570070D823E37A (section_id), INDEX IDX_82570070E03A87F6 (canonical_id), INDEX idx_jobboard_offer_listing (section_id, closed_at, sort_date, id), INDEX idx_jobboard_offer_first_seen (section_id, first_seen_at), INDEX idx_jobboard_offer_departement (section_id, departement), INDEX idx_jobboard_offer_contract (section_id, contract), UNIQUE INDEX uniq_jobboard_offer_identity (section_id, source, source_ref), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jobboard_token (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, selector VARCHAR(16) NOT NULL, verifier_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, last_used_ip VARCHAR(45) DEFAULT NULL, section_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_F4B62675D823E37A (section_id), INDEX IDX_F4B62675B03A8386 (created_by_id), UNIQUE INDEX uniq_jobboard_token_selector (selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE jobboard_batch ADD CONSTRAINT FK_538AD59A41DEE7B9 FOREIGN KEY (token_id) REFERENCES jobboard_token (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE jobboard_batch ADD CONSTRAINT FK_538AD59A74953CEA FOREIGN KEY (imported_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE jobboard_batch ADD CONSTRAINT FK_538AD59AD823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jobboard_batch_request ADD CONSTRAINT FK_C8FC689CF39EBE7A FOREIGN KEY (batch_id) REFERENCES jobboard_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jobboard_cursor ADD CONSTRAINT FK_54B0879ED823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jobboard_offer ADD CONSTRAINT FK_82570070D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jobboard_offer ADD CONSTRAINT FK_82570070E03A87F6 FOREIGN KEY (canonical_id) REFERENCES jobboard_offer (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE jobboard_token ADD CONSTRAINT FK_F4B62675D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jobboard_token ADD CONSTRAINT FK_F4B62675B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_batch DROP FOREIGN KEY FK_538AD59A41DEE7B9');
        $this->addSql('ALTER TABLE jobboard_batch DROP FOREIGN KEY FK_538AD59A74953CEA');
        $this->addSql('ALTER TABLE jobboard_batch DROP FOREIGN KEY FK_538AD59AD823E37A');
        $this->addSql('ALTER TABLE jobboard_batch_request DROP FOREIGN KEY FK_C8FC689CF39EBE7A');
        $this->addSql('ALTER TABLE jobboard_cursor DROP FOREIGN KEY FK_54B0879ED823E37A');
        $this->addSql('ALTER TABLE jobboard_offer DROP FOREIGN KEY FK_82570070D823E37A');
        $this->addSql('ALTER TABLE jobboard_offer DROP FOREIGN KEY FK_82570070E03A87F6');
        $this->addSql('ALTER TABLE jobboard_token DROP FOREIGN KEY FK_F4B62675D823E37A');
        $this->addSql('ALTER TABLE jobboard_token DROP FOREIGN KEY FK_F4B62675B03A8386');
        $this->addSql('DROP TABLE jobboard_batch');
        $this->addSql('DROP TABLE jobboard_batch_request');
        $this->addSql('DROP TABLE jobboard_cursor');
        $this->addSql('DROP TABLE jobboard_offer');
        $this->addSql('DROP TABLE jobboard_token');
    }
}
