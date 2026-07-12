<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712121945 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace SequenceTemplate/LibraryResource Niveau/Option/Bloc relations with free-text per-teacher tags';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE library_bloc_tag (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, creation_date DATETIME NOT NULL, teacher_id INT NOT NULL, INDEX IDX_D5CF6E5D41807E1D (teacher_id), UNIQUE INDEX library_bloc_tag_teacher_label (teacher_id, label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE library_niveau_tag (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, creation_date DATETIME NOT NULL, teacher_id INT NOT NULL, INDEX IDX_16FD614641807E1D (teacher_id), UNIQUE INDEX library_niveau_tag_teacher_label (teacher_id, label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE library_option_tag (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, creation_date DATETIME NOT NULL, teacher_id INT NOT NULL, INDEX IDX_51E354B841807E1D (teacher_id), UNIQUE INDEX library_option_tag_teacher_label (teacher_id, label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE library_resource_bloc_tag (library_resource_id INT NOT NULL, library_bloc_tag_id INT NOT NULL, INDEX IDX_38BB7D95D065B401 (library_resource_id), INDEX IDX_38BB7D95F280E10E (library_bloc_tag_id), PRIMARY KEY (library_resource_id, library_bloc_tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sequence_template_bloc_tag (sequence_template_id INT NOT NULL, library_bloc_tag_id INT NOT NULL, INDEX IDX_3974481DA31F2F3E (sequence_template_id), INDEX IDX_3974481DF280E10E (library_bloc_tag_id), PRIMARY KEY (sequence_template_id, library_bloc_tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE library_bloc_tag ADD CONSTRAINT FK_D5CF6E5D41807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE library_niveau_tag ADD CONSTRAINT FK_16FD614641807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE library_option_tag ADD CONSTRAINT FK_51E354B841807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE library_resource_bloc_tag ADD CONSTRAINT FK_38BB7D95D065B401 FOREIGN KEY (library_resource_id) REFERENCES library_resource (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_resource_bloc_tag ADD CONSTRAINT FK_38BB7D95F280E10E FOREIGN KEY (library_bloc_tag_id) REFERENCES library_bloc_tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sequence_template_bloc_tag ADD CONSTRAINT FK_3974481DA31F2F3E FOREIGN KEY (sequence_template_id) REFERENCES sequence_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sequence_template_bloc_tag ADD CONSTRAINT FK_3974481DF280E10E FOREIGN KEY (library_bloc_tag_id) REFERENCES library_bloc_tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_resource_bloc DROP FOREIGN KEY `FK_7B02157D5582E9C0`');
        $this->addSql('ALTER TABLE library_resource_bloc DROP FOREIGN KEY `FK_7B02157DD065B401`');
        $this->addSql('ALTER TABLE sequence_template_bloc DROP FOREIGN KEY `FK_440106F75582E9C0`');
        $this->addSql('ALTER TABLE sequence_template_bloc DROP FOREIGN KEY `FK_440106F7A31F2F3E`');
        $this->addSql('DROP TABLE library_resource_bloc');
        $this->addSql('DROP TABLE sequence_template_bloc');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY `FK_9A32050235983C93`');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY `FK_9A320502A7C41D6F`');
        $this->addSql('DROP INDEX IDX_9A32050235983C93 ON library_resource');
        $this->addSql('DROP INDEX IDX_9A320502A7C41D6F ON library_resource');
        $this->addSql('ALTER TABLE library_resource ADD niveau_tag_id INT DEFAULT NULL, ADD option_tag_id INT DEFAULT NULL, DROP cohort_id, DROP option_id');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A3205021EE6C08E FOREIGN KEY (niveau_tag_id) REFERENCES library_niveau_tag (id)');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT FK_9A320502F98261C6 FOREIGN KEY (option_tag_id) REFERENCES library_option_tag (id)');
        $this->addSql('CREATE INDEX IDX_9A3205021EE6C08E ON library_resource (niveau_tag_id)');
        $this->addSql('CREATE INDEX IDX_9A320502F98261C6 ON library_resource (option_tag_id)');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY `FK_1F5C4FAC35983C93`');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY `FK_1F5C4FACA7C41D6F`');
        $this->addSql('DROP INDEX IDX_1F5C4FAC35983C93 ON sequence_template');
        $this->addSql('DROP INDEX IDX_1F5C4FACA7C41D6F ON sequence_template');
        $this->addSql('ALTER TABLE sequence_template ADD option_tag_id INT DEFAULT NULL, DROP cohort_id, CHANGE option_id niveau_tag_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT FK_1F5C4FAC1EE6C08E FOREIGN KEY (niveau_tag_id) REFERENCES library_niveau_tag (id)');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT FK_1F5C4FACF98261C6 FOREIGN KEY (option_tag_id) REFERENCES library_option_tag (id)');
        $this->addSql('CREATE INDEX IDX_1F5C4FAC1EE6C08E ON sequence_template (niveau_tag_id)');
        $this->addSql('CREATE INDEX IDX_1F5C4FACF98261C6 ON sequence_template (option_tag_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE library_resource_bloc (library_resource_id INT NOT NULL, bloc_id INT NOT NULL, INDEX IDX_7B02157D5582E9C0 (bloc_id), INDEX IDX_7B02157DD065B401 (library_resource_id), PRIMARY KEY (library_resource_id, bloc_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sequence_template_bloc (sequence_template_id INT NOT NULL, bloc_id INT NOT NULL, INDEX IDX_440106F75582E9C0 (bloc_id), INDEX IDX_440106F7A31F2F3E (sequence_template_id), PRIMARY KEY (sequence_template_id, bloc_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE library_resource_bloc ADD CONSTRAINT `FK_7B02157D5582E9C0` FOREIGN KEY (bloc_id) REFERENCES bloc (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_resource_bloc ADD CONSTRAINT `FK_7B02157DD065B401` FOREIGN KEY (library_resource_id) REFERENCES library_resource (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sequence_template_bloc ADD CONSTRAINT `FK_440106F75582E9C0` FOREIGN KEY (bloc_id) REFERENCES bloc (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sequence_template_bloc ADD CONSTRAINT `FK_440106F7A31F2F3E` FOREIGN KEY (sequence_template_id) REFERENCES sequence_template (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_bloc_tag DROP FOREIGN KEY FK_D5CF6E5D41807E1D');
        $this->addSql('ALTER TABLE library_niveau_tag DROP FOREIGN KEY FK_16FD614641807E1D');
        $this->addSql('ALTER TABLE library_option_tag DROP FOREIGN KEY FK_51E354B841807E1D');
        $this->addSql('ALTER TABLE library_resource_bloc_tag DROP FOREIGN KEY FK_38BB7D95D065B401');
        $this->addSql('ALTER TABLE library_resource_bloc_tag DROP FOREIGN KEY FK_38BB7D95F280E10E');
        $this->addSql('ALTER TABLE sequence_template_bloc_tag DROP FOREIGN KEY FK_3974481DA31F2F3E');
        $this->addSql('ALTER TABLE sequence_template_bloc_tag DROP FOREIGN KEY FK_3974481DF280E10E');
        $this->addSql('DROP TABLE library_bloc_tag');
        $this->addSql('DROP TABLE library_niveau_tag');
        $this->addSql('DROP TABLE library_option_tag');
        $this->addSql('DROP TABLE library_resource_bloc_tag');
        $this->addSql('DROP TABLE sequence_template_bloc_tag');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A3205021EE6C08E');
        $this->addSql('ALTER TABLE library_resource DROP FOREIGN KEY FK_9A320502F98261C6');
        $this->addSql('DROP INDEX IDX_9A3205021EE6C08E ON library_resource');
        $this->addSql('DROP INDEX IDX_9A320502F98261C6 ON library_resource');
        $this->addSql('ALTER TABLE library_resource ADD cohort_id INT DEFAULT NULL, ADD option_id INT DEFAULT NULL, DROP niveau_tag_id, DROP option_tag_id');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT `FK_9A32050235983C93` FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE library_resource ADD CONSTRAINT `FK_9A320502A7C41D6F` FOREIGN KEY (option_id) REFERENCES `option` (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_9A32050235983C93 ON library_resource (cohort_id)');
        $this->addSql('CREATE INDEX IDX_9A320502A7C41D6F ON library_resource (option_id)');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY FK_1F5C4FAC1EE6C08E');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY FK_1F5C4FACF98261C6');
        $this->addSql('DROP INDEX IDX_1F5C4FAC1EE6C08E ON sequence_template');
        $this->addSql('DROP INDEX IDX_1F5C4FACF98261C6 ON sequence_template');
        $this->addSql('ALTER TABLE sequence_template ADD cohort_id INT NOT NULL, ADD option_id INT DEFAULT NULL, DROP niveau_tag_id, DROP option_tag_id');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT `FK_1F5C4FAC35983C93` FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT `FK_1F5C4FACA7C41D6F` FOREIGN KEY (option_id) REFERENCES `option` (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_1F5C4FAC35983C93 ON sequence_template (cohort_id)');
        $this->addSql('CREATE INDEX IDX_1F5C4FACA7C41D6F ON sequence_template (option_id)');
    }
}
