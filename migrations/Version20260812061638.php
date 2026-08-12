<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Course space (point 1): the three visibility columns and the opening trace.
 *
 * Every added column carries a default, so existing rows land on "hidden" / not visible: turning
 * this on must never publish a single sequence by accident.
 */
final class Version20260812061638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Course space: student visibility on sequences, séances and resources, plus the per-student opening trace.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE library_resource_instance_view (id INT AUTO_INCREMENT NOT NULL, first_opened_at DATETIME NOT NULL, last_opened_at DATETIME NOT NULL, open_count INT NOT NULL, resource_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_464CCD9E89329D25 (resource_id), INDEX IDX_464CCD9ECB944F1A (student_id), UNIQUE INDEX uniq_library_resource_instance_view (resource_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE library_resource_instance_view ADD CONSTRAINT FK_464CCD9E89329D25 FOREIGN KEY (resource_id) REFERENCES library_resource_instance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_resource_instance_view ADD CONSTRAINT FK_464CCD9ECB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_resource_instance ADD student_visible TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE seance_instance ADD student_visibility VARCHAR(20) DEFAULT \'hidden\' NOT NULL, ADD published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sequence_instance ADD student_visibility VARCHAR(20) DEFAULT \'hidden\' NOT NULL, ADD published_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE library_resource_instance_view DROP FOREIGN KEY FK_464CCD9E89329D25');
        $this->addSql('ALTER TABLE library_resource_instance_view DROP FOREIGN KEY FK_464CCD9ECB944F1A');
        $this->addSql('DROP TABLE library_resource_instance_view');
        $this->addSql('ALTER TABLE library_resource_instance DROP student_visible');
        $this->addSql('ALTER TABLE seance_instance DROP student_visibility, DROP published_at');
        $this->addSql('ALTER TABLE sequence_instance DROP student_visibility, DROP published_at');
    }
}
