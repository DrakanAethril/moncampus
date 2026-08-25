<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Closes the Courrier école on every formation, and makes closed the value a new one starts on
 * (design/validated/feature-access.md §12.1).
 *
 * The column arrived with lot 1, open everywhere, so that the resolver could read the formation axis
 * from the day it existed without changing a single screen four lots before the one allowed to. This
 * is that lot: the setting, the guard and the help sentence land together, and the value they govern
 * takes its real position at the same moment.
 *
 * **Nobody loses an access on the day this runs.** The Courrier école is not in service in
 * production, so there is nothing to seed and no rule of seeding to justify: every formation starts
 * closed and each one opens when the establishment decides, from Paramètres > Pédagogique >
 * Formations.
 *
 * What it does **not** touch, deliberately: the aliases. App\Service\StudentMailProvisioner runs at
 * account creation, before the account is enrolled in anything, and an address that has reached a
 * company is never regenerated. A student of a closed formation therefore has a working address whose
 * mail accumulates unread in `EmailMessage`; opening the formation later reveals the whole history
 * with nothing to replay, and nothing lands in the « courriers non rattachés » queue in the meantime
 * (§8.6).
 */
final class Version20260825160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Close program.school_mail_enabled everywhere, and default a new formation to closed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program CHANGE school_mail_enabled school_mail_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        // The DEFAULT above governs a formation created from now on; the rows that already exist
        // were opened by the lot 1 migration and are closed here, one statement, § 12.1.
        $this->addSql('UPDATE program SET school_mail_enabled = 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program CHANGE school_mail_enabled school_mail_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE program SET school_mail_enabled = 1');
    }
}
