<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An alternance's optional second tutor (`internship_tutor_link.secondary_tutor_id`): same rights
 * on the booklet as the principal, who stays the one tutor the platform names when it needs one.
 */
final class Version20261109090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alternance: optional secondary tutor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_tutor_link ADD secondary_tutor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D95782BB2EB8B7 FOREIGN KEY (secondary_tutor_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_80D95782BB2EB8B7 ON internship_tutor_link (secondary_tutor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D95782BB2EB8B7');
        $this->addSql('DROP INDEX IDX_80D95782BB2EB8B7 ON internship_tutor_link');
        $this->addSql('ALTER TABLE internship_tutor_link DROP secondary_tutor_id');
    }
}
