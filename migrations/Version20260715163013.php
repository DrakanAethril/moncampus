<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715163013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add booklet cover legal name (program default + per-Option override)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE internship_option_legal_name (id INT AUTO_INCREMENT NOT NULL, legal_name VARCHAR(255) NOT NULL, program_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_B3BD0C4A3EB8070A (program_id), INDEX IDX_B3BD0C4AA7C41D6F (option_id), UNIQUE INDEX internship_option_legal_name_unique (program_id, option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_option_legal_name ADD CONSTRAINT FK_B3BD0C4A3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_option_legal_name ADD CONSTRAINT FK_B3BD0C4AA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
        $this->addSql('ALTER TABLE internship_program_info ADD legal_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_option_legal_name DROP FOREIGN KEY FK_B3BD0C4A3EB8070A');
        $this->addSql('ALTER TABLE internship_option_legal_name DROP FOREIGN KEY FK_B3BD0C4AA7C41D6F');
        $this->addSql('DROP TABLE internship_option_legal_name');
        $this->addSql('ALTER TABLE internship_program_info DROP legal_name');
    }
}
