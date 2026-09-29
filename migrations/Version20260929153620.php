<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SIRET des entreprises: who confirmed an employer's SIRET, and who set it aside as « pas de SIRET
 * trouvable » (design/validated/siret-entreprises.md, §5.1).
 *
 * Stamps nothing, by decision: every SIRET already recorded was typed or imported without anyone
 * seeing what it designates, so every one becomes « à confirmer » and heads the queue.
 *
 * The numbers themselves are brought to their fourteen digits - the spaces and dots they were
 * sometimes typed with would otherwise keep two fiches carrying the same SIRET from being noticed.
 */
final class Version20260929153620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enterprise: SIRET confirmation and « pas de SIRET trouvable » set-aside';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enterprise ADD siret_confirmed_at DATETIME DEFAULT NULL, ADD siret_not_found_at DATETIME DEFAULT NULL, ADD siret_confirmed_by_id INT DEFAULT NULL, ADD siret_not_found_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE enterprise ADD CONSTRAINT FK_B1B36A0323BD536C FOREIGN KEY (siret_confirmed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE enterprise ADD CONSTRAINT FK_B1B36A03D3B1EFA8 FOREIGN KEY (siret_not_found_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B1B36A0323BD536C ON enterprise (siret_confirmed_by_id)');
        $this->addSql('CREATE INDEX IDX_B1B36A03D3B1EFA8 ON enterprise (siret_not_found_by_id)');
        $this->addSql("UPDATE enterprise SET siret = NULLIF(REPLACE(REPLACE(REPLACE(TRIM(siret), ' ', ''), '.', ''), '-', ''), '') WHERE siret IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE enterprise DROP FOREIGN KEY FK_B1B36A0323BD536C');
        $this->addSql('ALTER TABLE enterprise DROP FOREIGN KEY FK_B1B36A03D3B1EFA8');
        $this->addSql('DROP INDEX IDX_B1B36A0323BD536C ON enterprise');
        $this->addSql('DROP INDEX IDX_B1B36A03D3B1EFA8 ON enterprise');
        $this->addSql('ALTER TABLE enterprise DROP siret_confirmed_at, DROP siret_not_found_at, DROP siret_confirmed_by_id, DROP siret_not_found_by_id');
    }
}
