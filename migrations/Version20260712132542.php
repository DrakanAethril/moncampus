<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712132542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename skill_level\'s FK-supporting index names to match the renamed table (cosmetic, no structural/data change)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_a7ed78d53eb8070a TO IDX_BFC25F2F3EB8070A');
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_a7ed78d5b03a8386 TO IDX_BFC25F2FB03A8386');
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_a7ed78d5f5a2e305 TO IDX_BFC25F2FF5A2E305');
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_a7ed78d5e562d849 TO IDX_BFC25F2FE562D849');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_bfc25f2fb03a8386 TO IDX_A7ED78D5B03A8386');
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_bfc25f2ff5a2e305 TO IDX_A7ED78D5F5A2E305');
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_bfc25f2fe562d849 TO IDX_A7ED78D5E562D849');
        $this->addSql('ALTER TABLE skill_level RENAME INDEX idx_bfc25f2f3eb8070a TO IDX_A7ED78D53EB8070A');
    }
}
