<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Jobboard gains its second axis: each formation says who reads the veille's offers, on top of
 * the role matrix that already gates the feature.
 *
 * Every existing formation is set to « Masqué », which is the same state they are in today: the
 * `jobboard` feature is named by no role (App\Enum\Feature::defaultRoles()), so nobody but an
 * administrator reads the board, and an administrator is outside this rule. Nothing is taken away
 * from anybody the day this ships - the column opens formation by formation, which is the point.
 */
final class Version20260914083000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le jobboard se règle aussi par formation : Program.jobboard_visibility, masqué partout au départ';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE program ADD jobboard_visibility VARCHAR(20) DEFAULT 'hidden' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program DROP jobboard_visibility');
    }
}
