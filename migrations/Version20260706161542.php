<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706161542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE program_student (program_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_3CC957E73EB8070A (program_id), INDEX IDX_3CC957E7A76ED395 (user_id), PRIMARY KEY (program_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program_teacher (program_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_3B1C5E013EB8070A (program_id), INDEX IDX_3B1C5E01A76ED395 (user_id), PRIMARY KEY (program_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE program_student ADD CONSTRAINT FK_3CC957E73EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program_student ADD CONSTRAINT FK_3CC957E7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program_teacher ADD CONSTRAINT FK_3B1C5E013EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program_teacher ADD CONSTRAINT FK_3B1C5E01A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program_student DROP FOREIGN KEY FK_3CC957E73EB8070A');
        $this->addSql('ALTER TABLE program_student DROP FOREIGN KEY FK_3CC957E7A76ED395');
        $this->addSql('ALTER TABLE program_teacher DROP FOREIGN KEY FK_3B1C5E013EB8070A');
        $this->addSql('ALTER TABLE program_teacher DROP FOREIGN KEY FK_3B1C5E01A76ED395');
        $this->addSql('DROP TABLE program_student');
        $this->addSql('DROP TABLE program_teacher');
    }
}
