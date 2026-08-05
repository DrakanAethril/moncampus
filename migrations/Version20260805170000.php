<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Local suppression list for school mail (design_handoff_courrier_ecole_infra §6).
 *
 * Addresses SES reported as permanently dead, and those whose owner marked us as spam. Both damage
 * the sending domain's reputation, and enough of them stop the whole school from being able to write
 * to anyone.
 *
 * Kept locally rather than relying on SES's own list so the student is told while they are typing
 * the address, instead of discovering a week later that nothing ever left.
 */
final class Version20260805170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds suppressed_email_address: the local list of addresses school mail no longer writes to.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE suppressed_email_address (id INT AUTO_INCREMENT NOT NULL, address VARCHAR(255) NOT NULL, reason VARCHAR(20) NOT NULL, detail VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_suppressed_email_address (address), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE suppressed_email_address');
    }
}
