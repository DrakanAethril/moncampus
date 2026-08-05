<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A student's démarches stop borrowing the UFA's companies (design_handoff_stage_alternance).
 *
 * `job_application` used to point at `enterprise`, the staff-curated company repository the UFA
 * module fills in for contracts and tutors. A student hunting for a placement was therefore writing
 * into that repository, one company per address typed - which is neither their job nor what the
 * repository is for. The démarche is now named by the student, and unique for them within a class.
 *
 * Existing data is carried over, not dropped: each démarche takes the name of the company it was
 * attached to, and the class the student was enrolled in. Companies that only ever existed because a
 * student typed an address - created by a ROLE_STUDENT account and never used by an UFA tutor link -
 * are removed with them; every other company is left untouched.
 */
final class Version20260805223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replaces job_application.enterprise_id with a student-named démarche, scoped to a program.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_application ADD program_id INT DEFAULT NULL, ADD name VARCHAR(255) DEFAULT \'\' NOT NULL');

        // The name each démarche inherits: the company it was grouped under, which is exactly what
        // the screens were already displaying.
        $this->addSql('UPDATE job_application a JOIN enterprise e ON e.id = a.enterprise_id SET a.name = e.name');

        // The student's most recent active class. Historic démarches of a student who has since left
        // simply stay outside any class, which the nullable column allows.
        $this->addSql('UPDATE job_application a
            SET a.program_id = (
                SELECT ps.program_id
                FROM program_student ps
                JOIN program p ON p.id = ps.program_id
                WHERE ps.user_id = a.student_id AND p.inactive_date IS NULL
                ORDER BY p.id DESC
                LIMIT 1
            )');

        // Two companies could share a name; the unique index about to be created would not survive
        // it. Suffixing the later ones keeps both démarches and their mails rather than failing.
        $this->addSql('UPDATE job_application a
            JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY student_id, program_id, name ORDER BY id) AS rn
                FROM job_application
            ) d ON d.id = a.id
            SET a.name = CONCAT(a.name, \' (\', d.rn, \')\')
            WHERE d.rn > 1');

        $this->addSql('ALTER TABLE job_application DROP FOREIGN KEY FK_C737C688A97D1AC3');
        $this->addSql('DROP INDEX idx_job_application_enterprise ON job_application');
        $this->addSql('ALTER TABLE job_application DROP enterprise_id');
        $this->addSql('ALTER TABLE job_application ALTER name DROP DEFAULT');
        $this->addSql('ALTER TABLE job_application ADD CONSTRAINT FK_C737C6883EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C737C6883EB8070A ON job_application (program_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_application_name ON job_application (student_id, program_id, name)');

        // The companies the compose screen created behind the students' backs. Scoped twice - author
        // is a student, and no tutor link uses it - so that nothing an UFA screen relies on can go.
        $this->addSql('DELETE e FROM enterprise e
            JOIN `user` u ON u.id = e.created_by_id
            WHERE u.roles LIKE \'%ROLE_STUDENT%\'
              AND NOT EXISTS (SELECT 1 FROM internship_tutor_link l WHERE l.enterprise_id = e.id)');
    }

    public function down(Schema $schema): void
    {
        // The companies deleted above are not recreated: their names live on in job_application.name,
        // which is what a re-run of this migration would read anyway.
        $this->addSql('ALTER TABLE job_application ADD enterprise_id INT DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_job_application_name ON job_application');
        $this->addSql('ALTER TABLE job_application DROP FOREIGN KEY FK_C737C6883EB8070A');
        $this->addSql('DROP INDEX IDX_C737C6883EB8070A ON job_application');
        $this->addSql('ALTER TABLE job_application DROP program_id, DROP name');
        $this->addSql('CREATE INDEX idx_job_application_enterprise ON job_application (enterprise_id)');
    }
}
