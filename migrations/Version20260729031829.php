<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260729031829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE internship_livret_engagement (id INT AUTO_INCREMENT NOT NULL, signed_tutor_at DATETIME DEFAULT NULL, signed_student_at DATETIME DEFAULT NULL, signed_center_at DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, tutor_link_id INT NOT NULL, signed_tutor_by_id INT DEFAULT NULL, signed_student_by_id INT DEFAULT NULL, signed_center_by_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_2D3023D5C55C0FCE (tutor_link_id), INDEX IDX_2D3023D5A57D0A7B (signed_tutor_by_id), INDEX IDX_2D3023D53934F753 (signed_student_by_id), INDEX IDX_2D3023D5F0115744 (signed_center_by_id), INDEX IDX_2D3023D5B03A8386 (created_by_id), INDEX IDX_2D3023D5F5A2E305 (inactivated_by_id), INDEX IDX_2D3023D5E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_reminder (id INT AUTO_INCREMENT NOT NULL, step VARCHAR(30) NOT NULL, sent_at DATETIME NOT NULL, auto TINYINT NOT NULL, tutor_link_id INT NOT NULL, evaluation_period_id INT DEFAULT NULL, sent_by_id INT NOT NULL, INDEX IDX_505725F9C55C0FCE (tutor_link_id), INDEX IDX_505725F93E8BB15A (evaluation_period_id), INDEX IDX_505725F9A45BB98C (sent_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_supervisor_evaluation (id INT AUTO_INCREMENT NOT NULL, supervisor_signed_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, tutor_link_id INT NOT NULL, evaluation_period_id INT NOT NULL, supervisor_signed_by_id INT DEFAULT NULL, closed_by_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_7CD95BD8C55C0FCE (tutor_link_id), INDEX IDX_7CD95BD83E8BB15A (evaluation_period_id), INDEX IDX_7CD95BD83B1285AB (supervisor_signed_by_id), INDEX IDX_7CD95BD8E1FA7797 (closed_by_id), INDEX IDX_7CD95BD8B03A8386 (created_by_id), INDEX IDX_7CD95BD8F5A2E305 (inactivated_by_id), INDEX IDX_7CD95BD8E562D849 (last_updated_by_id), UNIQUE INDEX internship_supervisor_evaluation_unique (tutor_link_id, evaluation_period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_livret_engagement ADD CONSTRAINT FK_2D3023D5C55C0FCE FOREIGN KEY (tutor_link_id) REFERENCES internship_tutor_link (id)');
        $this->addSql('ALTER TABLE internship_livret_engagement ADD CONSTRAINT FK_2D3023D5A57D0A7B FOREIGN KEY (signed_tutor_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_livret_engagement ADD CONSTRAINT FK_2D3023D53934F753 FOREIGN KEY (signed_student_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_livret_engagement ADD CONSTRAINT FK_2D3023D5F0115744 FOREIGN KEY (signed_center_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_livret_engagement ADD CONSTRAINT FK_2D3023D5B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_livret_engagement ADD CONSTRAINT FK_2D3023D5F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_livret_engagement ADD CONSTRAINT FK_2D3023D5E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_reminder ADD CONSTRAINT FK_505725F9C55C0FCE FOREIGN KEY (tutor_link_id) REFERENCES internship_tutor_link (id)');
        $this->addSql('ALTER TABLE internship_reminder ADD CONSTRAINT FK_505725F93E8BB15A FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id)');
        $this->addSql('ALTER TABLE internship_reminder ADD CONSTRAINT FK_505725F9A45BB98C FOREIGN KEY (sent_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation ADD CONSTRAINT FK_7CD95BD8C55C0FCE FOREIGN KEY (tutor_link_id) REFERENCES internship_tutor_link (id)');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation ADD CONSTRAINT FK_7CD95BD83E8BB15A FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id)');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation ADD CONSTRAINT FK_7CD95BD83B1285AB FOREIGN KEY (supervisor_signed_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation ADD CONSTRAINT FK_7CD95BD8E1FA7797 FOREIGN KEY (closed_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation ADD CONSTRAINT FK_7CD95BD8B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation ADD CONSTRAINT FK_7CD95BD8F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation ADD CONSTRAINT FK_7CD95BD8E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE enterprise ADD siret VARCHAR(20) DEFAULT NULL, ADD phone VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD signed_at DATETIME DEFAULT NULL, ADD signed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF28D2EDD3FB FOREIGN KEY (signed_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_F267DF28D2EDD3FB ON internship_student_evaluation (signed_by_id)');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD signed_at DATETIME DEFAULT NULL, ADD signed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12DD2EDD3FB FOREIGN KEY (signed_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_F853D12DD2EDD3FB ON internship_team_evaluation (signed_by_id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD signed_at DATETIME DEFAULT NULL, ADD signed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EACD2EDD3FB FOREIGN KEY (signed_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_55825EACD2EDD3FB ON internship_tutor_evaluation (signed_by_id)');
        // DEFAULT 'apprentissage' backfills every pre-existing row (see ContractTypeCode) - staff
        // can correct it via the edit form afterward. Not kept as a permanent column default: new
        // rows always set it explicitly via InternshipTutorLink's own PHP-level default/form field.
        $this->addSql("ALTER TABLE internship_tutor_link ADD contract_type VARCHAR(30) DEFAULT 'apprentissage' NOT NULL, ADD supervisor_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE internship_tutor_link ALTER COLUMN contract_type DROP DEFAULT');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D9578219E9AC5F FOREIGN KEY (supervisor_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_80D9578219E9AC5F ON internship_tutor_link (supervisor_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_livret_engagement DROP FOREIGN KEY FK_2D3023D5C55C0FCE');
        $this->addSql('ALTER TABLE internship_livret_engagement DROP FOREIGN KEY FK_2D3023D5A57D0A7B');
        $this->addSql('ALTER TABLE internship_livret_engagement DROP FOREIGN KEY FK_2D3023D53934F753');
        $this->addSql('ALTER TABLE internship_livret_engagement DROP FOREIGN KEY FK_2D3023D5F0115744');
        $this->addSql('ALTER TABLE internship_livret_engagement DROP FOREIGN KEY FK_2D3023D5B03A8386');
        $this->addSql('ALTER TABLE internship_livret_engagement DROP FOREIGN KEY FK_2D3023D5F5A2E305');
        $this->addSql('ALTER TABLE internship_livret_engagement DROP FOREIGN KEY FK_2D3023D5E562D849');
        $this->addSql('ALTER TABLE internship_reminder DROP FOREIGN KEY FK_505725F9C55C0FCE');
        $this->addSql('ALTER TABLE internship_reminder DROP FOREIGN KEY FK_505725F93E8BB15A');
        $this->addSql('ALTER TABLE internship_reminder DROP FOREIGN KEY FK_505725F9A45BB98C');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation DROP FOREIGN KEY FK_7CD95BD8C55C0FCE');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation DROP FOREIGN KEY FK_7CD95BD83E8BB15A');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation DROP FOREIGN KEY FK_7CD95BD83B1285AB');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation DROP FOREIGN KEY FK_7CD95BD8E1FA7797');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation DROP FOREIGN KEY FK_7CD95BD8B03A8386');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation DROP FOREIGN KEY FK_7CD95BD8F5A2E305');
        $this->addSql('ALTER TABLE internship_supervisor_evaluation DROP FOREIGN KEY FK_7CD95BD8E562D849');
        $this->addSql('DROP TABLE internship_livret_engagement');
        $this->addSql('DROP TABLE internship_reminder');
        $this->addSql('DROP TABLE internship_supervisor_evaluation');
        $this->addSql('ALTER TABLE enterprise DROP siret, DROP phone');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF28D2EDD3FB');
        $this->addSql('DROP INDEX IDX_F267DF28D2EDD3FB ON internship_student_evaluation');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP signed_at, DROP signed_by_id');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12DD2EDD3FB');
        $this->addSql('DROP INDEX IDX_F853D12DD2EDD3FB ON internship_team_evaluation');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP signed_at, DROP signed_by_id');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EACD2EDD3FB');
        $this->addSql('DROP INDEX IDX_55825EACD2EDD3FB ON internship_tutor_evaluation');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP signed_at, DROP signed_by_id');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D9578219E9AC5F');
        $this->addSql('DROP INDEX IDX_80D9578219E9AC5F ON internship_tutor_link');
        $this->addSql('ALTER TABLE internship_tutor_link DROP contract_type, DROP supervisor_id');
    }
}
