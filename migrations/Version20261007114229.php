<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Murs collaboratifs » (design/design_handoff_murs_collaboratifs): a wall, its lists, their cards
 * and the comments under them, plus the two join tables a wall is shared through - named people
 * and whole classes.
 */
final class Version20261007114229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collaborative walls: wall, wall_list, wall_card, wall_comment, wall_member, wall_program';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wall (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, format VARCHAR(20) NOT NULL, background_color VARCHAR(7) DEFAULT NULL, background_image_key VARCHAR(255) DEFAULT NULL, authors_shown TINYINT NOT NULL, labels_shown TINYINT NOT NULL, counts_shown TINYINT NOT NULL, comments_enabled TINYINT NOT NULL, participants_may_add TINYINT NOT NULL, participants_may_edit_others TINYINT NOT NULL, moderated TINYINT NOT NULL, revision INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_13F5EFF67E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wall_member (wall_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_AB139C13C33923F1 (wall_id), INDEX IDX_AB139C13A76ED395 (user_id), PRIMARY KEY (wall_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wall_program (wall_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_48563832C33923F1 (wall_id), INDEX IDX_485638323EB8070A (program_id), PRIMARY KEY (wall_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wall_card (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, title VARCHAR(255) NOT NULL, label VARCHAR(40) DEFAULT NULL, label_tone VARCHAR(10) DEFAULT NULL, text LONGTEXT DEFAULT NULL, image_key VARCHAR(255) DEFAULT NULL, link_title VARCHAR(255) DEFAULT NULL, link_url VARCHAR(2000) DEFAULT NULL, file_key VARCHAR(255) DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, file_size INT DEFAULT NULL, checklist JSON NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, list_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_D0B9ECF23DAE168B (list_id), INDEX IDX_D0B9ECF2F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wall_comment (id INT AUTO_INCREMENT NOT NULL, text LONGTEXT NOT NULL, created_at DATETIME NOT NULL, card_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_4ECF1DDA4ACC9A20 (card_id), INDEX IDX_4ECF1DDAF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wall_list (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, color VARCHAR(7) DEFAULT NULL, position INT NOT NULL, wall_id INT NOT NULL, INDEX IDX_82658C39C33923F1 (wall_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE wall ADD CONSTRAINT FK_13F5EFF67E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wall_member ADD CONSTRAINT FK_AB139C13C33923F1 FOREIGN KEY (wall_id) REFERENCES wall (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wall_member ADD CONSTRAINT FK_AB139C13A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wall_program ADD CONSTRAINT FK_48563832C33923F1 FOREIGN KEY (wall_id) REFERENCES wall (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wall_program ADD CONSTRAINT FK_485638323EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wall_card ADD CONSTRAINT FK_D0B9ECF23DAE168B FOREIGN KEY (list_id) REFERENCES wall_list (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wall_card ADD CONSTRAINT FK_D0B9ECF2F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wall_comment ADD CONSTRAINT FK_4ECF1DDA4ACC9A20 FOREIGN KEY (card_id) REFERENCES wall_card (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wall_comment ADD CONSTRAINT FK_4ECF1DDAF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wall_list ADD CONSTRAINT FK_82658C39C33923F1 FOREIGN KEY (wall_id) REFERENCES wall (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wall DROP FOREIGN KEY FK_13F5EFF67E3C61F9');
        $this->addSql('ALTER TABLE wall_member DROP FOREIGN KEY FK_AB139C13C33923F1');
        $this->addSql('ALTER TABLE wall_member DROP FOREIGN KEY FK_AB139C13A76ED395');
        $this->addSql('ALTER TABLE wall_program DROP FOREIGN KEY FK_48563832C33923F1');
        $this->addSql('ALTER TABLE wall_program DROP FOREIGN KEY FK_485638323EB8070A');
        $this->addSql('ALTER TABLE wall_card DROP FOREIGN KEY FK_D0B9ECF23DAE168B');
        $this->addSql('ALTER TABLE wall_card DROP FOREIGN KEY FK_D0B9ECF2F675F31B');
        $this->addSql('ALTER TABLE wall_comment DROP FOREIGN KEY FK_4ECF1DDA4ACC9A20');
        $this->addSql('ALTER TABLE wall_comment DROP FOREIGN KEY FK_4ECF1DDAF675F31B');
        $this->addSql('ALTER TABLE wall_list DROP FOREIGN KEY FK_82658C39C33923F1');
        $this->addSql('DROP TABLE wall');
        $this->addSql('DROP TABLE wall_member');
        $this->addSql('DROP TABLE wall_program');
        $this->addSql('DROP TABLE wall_card');
        $this->addSql('DROP TABLE wall_comment');
        $this->addSql('DROP TABLE wall_list');
    }
}
