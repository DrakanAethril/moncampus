<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The e-CO app now sends, with each GPS fix, the radius the phone itself gives it; a fix it calls
 * vague is kept but no longer read (EcoTraceCleaner::plausible()). Null on every fix logged
 * before, and on every one an older app still sends.
 */
final class Version20260929114110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'e-CO : précision de chaque position GPS, telle que le téléphone la donne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_position_ping ADD accuracy DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_position_ping DROP accuracy');
    }
}
