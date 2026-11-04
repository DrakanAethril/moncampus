<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The serious games' targeting (design/validated/quiz-tournament.md §14): the three join tables of
 * StructureTargetsTrait on serious_game. Version20261104163800 moves the students' cells onto them.
 */
final class Version20261104163713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serious games: targeting tables (sections, filières, formations)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE serious_game_section (serious_game_id INT NOT NULL, section_id INT NOT NULL, INDEX IDX_1DB78F0E4858F903 (serious_game_id), INDEX IDX_1DB78F0ED823E37A (section_id), PRIMARY KEY (serious_game_id, section_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE serious_game_track (serious_game_id INT NOT NULL, track_id INT NOT NULL, INDEX IDX_6D6E9EE24858F903 (serious_game_id), INDEX IDX_6D6E9EE25ED23C43 (track_id), PRIMARY KEY (serious_game_id, track_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE serious_game_program (serious_game_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_A22982654858F903 (serious_game_id), INDEX IDX_A22982653EB8070A (program_id), PRIMARY KEY (serious_game_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE serious_game_section ADD CONSTRAINT FK_1DB78F0E4858F903 FOREIGN KEY (serious_game_id) REFERENCES serious_game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serious_game_section ADD CONSTRAINT FK_1DB78F0ED823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serious_game_track ADD CONSTRAINT FK_6D6E9EE24858F903 FOREIGN KEY (serious_game_id) REFERENCES serious_game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serious_game_track ADD CONSTRAINT FK_6D6E9EE25ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serious_game_program ADD CONSTRAINT FK_A22982654858F903 FOREIGN KEY (serious_game_id) REFERENCES serious_game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serious_game_program ADD CONSTRAINT FK_A22982653EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE serious_game_section DROP FOREIGN KEY FK_1DB78F0E4858F903');
        $this->addSql('ALTER TABLE serious_game_section DROP FOREIGN KEY FK_1DB78F0ED823E37A');
        $this->addSql('ALTER TABLE serious_game_track DROP FOREIGN KEY FK_6D6E9EE24858F903');
        $this->addSql('ALTER TABLE serious_game_track DROP FOREIGN KEY FK_6D6E9EE25ED23C43');
        $this->addSql('ALTER TABLE serious_game_program DROP FOREIGN KEY FK_A22982654858F903');
        $this->addSql('ALTER TABLE serious_game_program DROP FOREIGN KEY FK_A22982653EB8070A');
        $this->addSql('DROP TABLE serious_game_section');
        $this->addSql('DROP TABLE serious_game_track');
        $this->addSql('DROP TABLE serious_game_program');
    }
}
