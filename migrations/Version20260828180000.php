<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The promotion trophy leaves the catalogue, and `tier_code` with it.
 *
 * It was the last automatic reward that was not a level frame: « octroyé à la tête de classe » is a
 * rank over the whole of a promotion's results, and this application does not hold every grade - it
 * is the pedagogical platform, not the school records. Nothing could ever award it, so it stood on
 * the catalogue screen marked « automatique » beside six frames the machine really does grant, which
 * reads as a mechanism rather than as the intention it was.
 *
 * A formation that wants such a trophy still can: it creates one in its own catalogue and hands it
 * over by name, which is what every other symbolic reward already does.
 *
 * `tier_code` goes in the same pass because the trophy was its only holder - the three index tiers
 * that used to share it were removed on 2026-08-28 (Version20260828160000), and `level` is what
 * says « this entry is granted by the machine » ever since.
 */
final class Version20260828180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the promotion trophy and the tier_code column it was the last holder of';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM reward_grant WHERE item_id IN (SELECT id FROM reward_item WHERE program_id IS NULL AND tier_code = 'trophy')");
        $this->addSql("DELETE FROM reward_item WHERE program_id IS NULL AND tier_code = 'trophy'");
        $this->addSql('ALTER TABLE reward_item DROP tier_code');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reward_item ADD tier_code VARCHAR(20) DEFAULT NULL');
        $this->addSql("INSERT INTO reward_item (program_id, label, description, nature, scope, icon, level, quantity, active, tier_code) VALUES (NULL, 'Trophée de promo', 'Octroyé à la tête de classe, et mis à l''honneur à la remise des bulletins.', 'symbolic', 'student', '🏆', NULL, NULL, 1, 'trophy')");
    }
}
