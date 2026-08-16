<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260804162105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déplace la désignation de l\'adresse Courrier école principale de email_alias.is_primary vers user.primary_alias_id.';
    }

    /**
     * « Which is this student's address? » is a single-valued fact about the student, and not a
     * property of each of their aliases. Carried by a boolean spread over N rows, it would have had
     * to be defended by a partial unique index - « unique among the rows where is_primary is true » -
     * which MySQL cannot express: UNIQUE(is_primary) would allow only one main row for the whole
     * school, and UNIQUE(user_id, is_primary) would forbid a student from having more than one
     * secondary alias, which is precisely the common case (login, addresses kept after a change of
     * civil status).
     *
     * A nullable pointer on the user side makes the invariant true by construction: a column holds
     * only one value.
     *
     * Done while the table is empty - no data migration is therefore needed.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias DROP is_primary');
        $this->addSql('ALTER TABLE user ADD primary_alias_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6497F292713 FOREIGN KEY (primary_alias_id) REFERENCES email_alias (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D6497F292713 ON user (primary_alias_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_alias ADD is_primary TINYINT NOT NULL');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D6497F292713');
        $this->addSql('DROP INDEX IDX_8D93D6497F292713 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP primary_alias_id');
    }
}
