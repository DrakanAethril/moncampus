<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content sharing between teachers, lot 1 - the table and its two audience tables
 * (design/validated/content-sharing-between-teachers.md).
 *
 * **Five nullable foreign keys, exactly one filled, and never a `(target_type, target_id)` pair.**
 * This repository already wrote down why (design/validated/file-library.md, "The link model"): a
 * polymorphic pair "reads well and it lies" - nothing deletes its rows when the host disappears.
 * The failure would be worse here: a share row surviving its deleted séquence grants access to
 * nothing and still shows in a colleague's list. Six `ON DELETE CASCADE` constraints cannot drift,
 * and the shape is not an invention - `library_resource` already carries three nullable parent FKs
 * with exactly one set.
 *
 * One table and not five, because the audience **is** the feature and it is identical for the five
 * subjects: three scopes, a note, a revocation, one list of "shared with me". Five tables would be
 * five copies of that code, and the fifth would be the one where the group hierarchy is walked
 * wrong.
 *
 * `revoked_at` rather than a `DELETE`: a withdrawn share has usually already been duplicated, and
 * « à qui l'ai-je donné ? » is a question about the past that an erased row cannot answer. It also
 * makes re-granting a click instead of an audience to rebuild.
 *
 * `duplicated_by` and `dismissed_by` are JSON rather than two more tables. Neither is derivable - a
 * duplication is deliberately a copy with **no link back** to its source, so nothing else in the
 * schema knows it happened - and both are read one share at a time, never joined nor aggregated.
 *
 * No column carries a `DEFAULT`: the table is created whole here, so the drift a column default
 * causes cannot start. A column added to it later must set its default in the `ALTER` and drop it
 * afterwards.
 */
final class Version20260818210136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partage de contenu entre enseignants : content_share et ses deux tables d\'audience';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_share (id INT AUTO_INCREMENT NOT NULL, scope VARCHAR(20) NOT NULL, note LONGTEXT DEFAULT NULL, creation_date DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, duplicated_by JSON NOT NULL, dismissed_by JSON NOT NULL, sequence_template_id INT DEFAULT NULL, seance_template_id INT DEFAULT NULL, quiz_template_id INT DEFAULT NULL, library_node_id INT DEFAULT NULL, progression_id INT DEFAULT NULL, owner_id INT NOT NULL, INDEX IDX_4C5C988A31F2F3E (sequence_template_id), INDEX IDX_4C5C9883808C4F3 (seance_template_id), INDEX IDX_4C5C9882AFC1C18 (quiz_template_id), INDEX IDX_4C5C9883CCC27E7 (library_node_id), INDEX IDX_4C5C988AF229C18 (progression_id), INDEX idx_content_share_owner (owner_id), INDEX idx_content_share_scope (scope), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_share_user (share_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_A96D6D222AE63FDB (share_id), INDEX IDX_A96D6D22A76ED395 (user_id), PRIMARY KEY (share_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_share_group (share_id INT NOT NULL, group_id INT NOT NULL, INDEX IDX_B78402AE2AE63FDB (share_id), INDEX IDX_B78402AEFE54D947 (group_id), PRIMARY KEY (share_id, group_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE content_share ADD CONSTRAINT FK_4C5C988A31F2F3E FOREIGN KEY (sequence_template_id) REFERENCES sequence_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share ADD CONSTRAINT FK_4C5C9883808C4F3 FOREIGN KEY (seance_template_id) REFERENCES seance_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share ADD CONSTRAINT FK_4C5C9882AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share ADD CONSTRAINT FK_4C5C9883CCC27E7 FOREIGN KEY (library_node_id) REFERENCES file_library_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share ADD CONSTRAINT FK_4C5C988AF229C18 FOREIGN KEY (progression_id) REFERENCES progression (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share ADD CONSTRAINT FK_4C5C9887E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share_user ADD CONSTRAINT FK_A96D6D222AE63FDB FOREIGN KEY (share_id) REFERENCES content_share (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share_user ADD CONSTRAINT FK_A96D6D22A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share_group ADD CONSTRAINT FK_B78402AE2AE63FDB FOREIGN KEY (share_id) REFERENCES content_share (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_share_group ADD CONSTRAINT FK_B78402AEFE54D947 FOREIGN KEY (group_id) REFERENCES `group` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_share DROP FOREIGN KEY FK_4C5C988A31F2F3E');
        $this->addSql('ALTER TABLE content_share DROP FOREIGN KEY FK_4C5C9883808C4F3');
        $this->addSql('ALTER TABLE content_share DROP FOREIGN KEY FK_4C5C9882AFC1C18');
        $this->addSql('ALTER TABLE content_share DROP FOREIGN KEY FK_4C5C9883CCC27E7');
        $this->addSql('ALTER TABLE content_share DROP FOREIGN KEY FK_4C5C988AF229C18');
        $this->addSql('ALTER TABLE content_share DROP FOREIGN KEY FK_4C5C9887E3C61F9');
        $this->addSql('ALTER TABLE content_share_user DROP FOREIGN KEY FK_A96D6D222AE63FDB');
        $this->addSql('ALTER TABLE content_share_user DROP FOREIGN KEY FK_A96D6D22A76ED395');
        $this->addSql('ALTER TABLE content_share_group DROP FOREIGN KEY FK_B78402AE2AE63FDB');
        $this->addSql('ALTER TABLE content_share_group DROP FOREIGN KEY FK_B78402AEFE54D947');
        $this->addSql('DROP TABLE content_share');
        $this->addSql('DROP TABLE content_share_user');
        $this->addSql('DROP TABLE content_share_group');
    }
}
