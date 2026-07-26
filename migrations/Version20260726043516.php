<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726043516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace Program.period_group_id (0-1 PeriodGroup) with the program_period_group join table (many PeriodGroups per Program, ordered by priority), preserving existing links as priority 1.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE program_period_group (id INT AUTO_INCREMENT NOT NULL, priority INT NOT NULL, program_id INT NOT NULL, period_group_id INT NOT NULL, INDEX IDX_1FCD3F873EB8070A (program_id), INDEX IDX_1FCD3F879B1FE924 (period_group_id), UNIQUE INDEX program_period_group_unique (program_id, period_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE program_period_group ADD CONSTRAINT FK_1FCD3F873EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_period_group ADD CONSTRAINT FK_1FCD3F879B1FE924 FOREIGN KEY (period_group_id) REFERENCES period_group (id)');
        // Preserve existing Program->PeriodGroup links as priority 1 before the old column is dropped.
        $this->addSql('INSERT INTO program_period_group (program_id, period_group_id, priority) SELECT id, period_group_id, 1 FROM program WHERE period_group_id IS NOT NULL');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY `FK_92ED77849B1FE924`');
        $this->addSql('DROP INDEX IDX_92ED77849B1FE924 ON program');
        $this->addSql('ALTER TABLE program DROP period_group_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program ADD period_group_id INT DEFAULT NULL');
        // Best-effort restore: only the highest-priority (lowest priority number) link per Program
        // fits back into the single old column - any additional attached groups are necessarily
        // lost on downgrade, since the old schema had no room for them.
        $this->addSql(<<<'SQL'
            UPDATE program p
            JOIN (
                SELECT program_id, MIN(priority) AS min_priority
                FROM program_period_group
                GROUP BY program_id
            ) best ON best.program_id = p.id
            JOIN program_period_group ppg
                ON ppg.program_id = best.program_id AND ppg.priority = best.min_priority
            SET p.period_group_id = ppg.period_group_id
            SQL);
        $this->addSql('ALTER TABLE program_period_group DROP FOREIGN KEY FK_1FCD3F873EB8070A');
        $this->addSql('ALTER TABLE program_period_group DROP FOREIGN KEY FK_1FCD3F879B1FE924');
        $this->addSql('DROP TABLE program_period_group');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT `FK_92ED77849B1FE924` FOREIGN KEY (period_group_id) REFERENCES period_group (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_92ED77849B1FE924 ON program (period_group_id)');
    }
}
