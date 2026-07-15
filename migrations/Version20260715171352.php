<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the "professeur référent" concept on Program: program_referent_teacher (a tagged subset of
 * program_teacher, see Program::addReferentTeacher()) and program_referent_teacher_option (the
 * referent-teacher equivalent of program_teacher_option, for per-Option scoping).
 */
final class Version20260715171352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add program_referent_teacher and program_referent_teacher_option (referent teachers on a Program, optionally scoped per Option)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE program_referent_teacher (program_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_3ECA92D73EB8070A (program_id), INDEX IDX_3ECA92D7A76ED395 (user_id), PRIMARY KEY (program_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program_referent_teacher_option (id INT AUTO_INCREMENT NOT NULL, program_id INT NOT NULL, referent_teacher_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_2E2E12583EB8070A (program_id), INDEX IDX_2E2E12587E3BAE1D (referent_teacher_id), INDEX IDX_2E2E1258A7C41D6F (option_id), UNIQUE INDEX program_referent_teacher_option_unique (program_id, referent_teacher_id, option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE program_referent_teacher ADD CONSTRAINT FK_3ECA92D73EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program_referent_teacher ADD CONSTRAINT FK_3ECA92D7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program_referent_teacher_option ADD CONSTRAINT FK_2E2E12583EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_referent_teacher_option ADD CONSTRAINT FK_2E2E12587E3BAE1D FOREIGN KEY (referent_teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program_referent_teacher_option ADD CONSTRAINT FK_2E2E1258A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program_referent_teacher DROP FOREIGN KEY FK_3ECA92D73EB8070A');
        $this->addSql('ALTER TABLE program_referent_teacher DROP FOREIGN KEY FK_3ECA92D7A76ED395');
        $this->addSql('ALTER TABLE program_referent_teacher_option DROP FOREIGN KEY FK_2E2E12583EB8070A');
        $this->addSql('ALTER TABLE program_referent_teacher_option DROP FOREIGN KEY FK_2E2E12587E3BAE1D');
        $this->addSql('ALTER TABLE program_referent_teacher_option DROP FOREIGN KEY FK_2E2E1258A7C41D6F');
        $this->addSql('DROP TABLE program_referent_teacher');
        $this->addSql('DROP TABLE program_referent_teacher_option');
    }
}
