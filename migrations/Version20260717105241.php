<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717105241 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add e-CO tables (parcours/checkpoint/course/team/runner/scan/position ping/app event).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eco_app_event (id INT AUTO_INCREMENT NOT NULL, left_at DATETIME NOT NULL, returned_at DATETIME DEFAULT NULL, duration_seconds INT DEFAULT NULL, runner_id INT NOT NULL, INDEX IDX_C323BDC83C7FB593 (runner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_checkpoint (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, position INT NOT NULL, name VARCHAR(255) NOT NULL, note VARCHAR(255) DEFAULT NULL, short_code VARCHAR(20) NOT NULL, tolerance_meters INT NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, located_at DATETIME DEFAULT NULL, parcours_id INT NOT NULL, INDEX IDX_37F66B406E38C0DB (parcours_id), UNIQUE INDEX eco_checkpoint_short_code_unique (short_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_checkpoint_scan (id INT AUTO_INCREMENT NOT NULL, scanned_at DATETIME NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, distance_meters DOUBLE PRECISION DEFAULT NULL, method VARCHAR(20) NOT NULL, result VARCHAR(20) NOT NULL, attempt_sequence INT NOT NULL, runner_id INT NOT NULL, checkpoint_id INT NOT NULL, INDEX IDX_C0C44A83C7FB593 (runner_id), INDEX IDX_C0C44A8F27C615F (checkpoint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_course (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, code VARCHAR(6) NOT NULL, mode VARCHAR(20) NOT NULL, teams_enabled TINYINT NOT NULL, map_visibility VARCHAR(30) NOT NULL, safety_alerts_enabled TINYINT NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, parcours_id INT NOT NULL, teacher_id INT NOT NULL, INDEX IDX_CAA0FF706E38C0DB (parcours_id), INDEX IDX_CAA0FF7041807E1D (teacher_id), UNIQUE INDEX eco_course_code_unique (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_parcours (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, teacher_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_7386FD9E41807E1D (teacher_id), INDEX IDX_7386FD9EB03A8386 (created_by_id), INDEX IDX_7386FD9EF5A2E305 (inactivated_by_id), INDEX IDX_7386FD9EE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_position_ping (id INT AUTO_INCREMENT NOT NULL, recorded_at DATETIME NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, runner_id INT NOT NULL, INDEX IDX_3DDF7E3C7FB593 (runner_id), INDEX eco_position_ping_runner_recorded_idx (runner_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_runner (id INT AUTO_INCREMENT NOT NULL, pseudo VARCHAR(100) NOT NULL, join_token VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, score_value INT DEFAULT NULL, sos_active TINYINT NOT NULL, sos_at DATETIME DEFAULT NULL, last_latitude DOUBLE PRECISION DEFAULT NULL, last_longitude DOUBLE PRECISION DEFAULT NULL, last_position_at DATETIME DEFAULT NULL, app_left_at DATETIME DEFAULT NULL, joined_at DATETIME NOT NULL, course_id INT NOT NULL, team_id INT DEFAULT NULL, INDEX IDX_25151BF7591CC992 (course_id), INDEX IDX_25151BF7296CD8AE (team_id), UNIQUE INDEX eco_runner_join_token_unique (join_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE eco_team (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, course_id INT NOT NULL, INDEX IDX_E132BB58591CC992 (course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE eco_app_event ADD CONSTRAINT FK_C323BDC83C7FB593 FOREIGN KEY (runner_id) REFERENCES eco_runner (id)');
        $this->addSql('ALTER TABLE eco_checkpoint ADD CONSTRAINT FK_37F66B406E38C0DB FOREIGN KEY (parcours_id) REFERENCES eco_parcours (id)');
        $this->addSql('ALTER TABLE eco_checkpoint_scan ADD CONSTRAINT FK_C0C44A83C7FB593 FOREIGN KEY (runner_id) REFERENCES eco_runner (id)');
        $this->addSql('ALTER TABLE eco_checkpoint_scan ADD CONSTRAINT FK_C0C44A8F27C615F FOREIGN KEY (checkpoint_id) REFERENCES eco_checkpoint (id)');
        $this->addSql('ALTER TABLE eco_course ADD CONSTRAINT FK_CAA0FF706E38C0DB FOREIGN KEY (parcours_id) REFERENCES eco_parcours (id)');
        $this->addSql('ALTER TABLE eco_course ADD CONSTRAINT FK_CAA0FF7041807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE eco_parcours ADD CONSTRAINT FK_7386FD9E41807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE eco_parcours ADD CONSTRAINT FK_7386FD9EB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE eco_parcours ADD CONSTRAINT FK_7386FD9EF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE eco_parcours ADD CONSTRAINT FK_7386FD9EE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE eco_position_ping ADD CONSTRAINT FK_3DDF7E3C7FB593 FOREIGN KEY (runner_id) REFERENCES eco_runner (id)');
        $this->addSql('ALTER TABLE eco_runner ADD CONSTRAINT FK_25151BF7591CC992 FOREIGN KEY (course_id) REFERENCES eco_course (id)');
        $this->addSql('ALTER TABLE eco_runner ADD CONSTRAINT FK_25151BF7296CD8AE FOREIGN KEY (team_id) REFERENCES eco_team (id)');
        $this->addSql('ALTER TABLE eco_team ADD CONSTRAINT FK_E132BB58591CC992 FOREIGN KEY (course_id) REFERENCES eco_course (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eco_app_event DROP FOREIGN KEY FK_C323BDC83C7FB593');
        $this->addSql('ALTER TABLE eco_checkpoint DROP FOREIGN KEY FK_37F66B406E38C0DB');
        $this->addSql('ALTER TABLE eco_checkpoint_scan DROP FOREIGN KEY FK_C0C44A83C7FB593');
        $this->addSql('ALTER TABLE eco_checkpoint_scan DROP FOREIGN KEY FK_C0C44A8F27C615F');
        $this->addSql('ALTER TABLE eco_course DROP FOREIGN KEY FK_CAA0FF706E38C0DB');
        $this->addSql('ALTER TABLE eco_course DROP FOREIGN KEY FK_CAA0FF7041807E1D');
        $this->addSql('ALTER TABLE eco_parcours DROP FOREIGN KEY FK_7386FD9E41807E1D');
        $this->addSql('ALTER TABLE eco_parcours DROP FOREIGN KEY FK_7386FD9EB03A8386');
        $this->addSql('ALTER TABLE eco_parcours DROP FOREIGN KEY FK_7386FD9EF5A2E305');
        $this->addSql('ALTER TABLE eco_parcours DROP FOREIGN KEY FK_7386FD9EE562D849');
        $this->addSql('ALTER TABLE eco_position_ping DROP FOREIGN KEY FK_3DDF7E3C7FB593');
        $this->addSql('ALTER TABLE eco_runner DROP FOREIGN KEY FK_25151BF7591CC992');
        $this->addSql('ALTER TABLE eco_runner DROP FOREIGN KEY FK_25151BF7296CD8AE');
        $this->addSql('ALTER TABLE eco_team DROP FOREIGN KEY FK_E132BB58591CC992');
        $this->addSql('DROP TABLE eco_app_event');
        $this->addSql('DROP TABLE eco_checkpoint');
        $this->addSql('DROP TABLE eco_checkpoint_scan');
        $this->addSql('DROP TABLE eco_course');
        $this->addSql('DROP TABLE eco_parcours');
        $this->addSql('DROP TABLE eco_position_ping');
        $this->addSql('DROP TABLE eco_runner');
        $this->addSql('DROP TABLE eco_team');
    }
}
