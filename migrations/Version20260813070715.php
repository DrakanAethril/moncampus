<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The four fields a real BTS séquence sheet carries and the library had nowhere to put, on the
 * template layer and on its frozen copy: differentiation and watch_points on the séquence, materials
 * and watch_points on the séance (design/comparaison/conception_sequence_seance_ia.md, § 5).
 *
 * Eight nullable columns, no row rewritten and nothing to backfill: a séquence that already exists
 * simply has none of this said yet, which is the state a séquence created today starts in too.
 */
final class Version20260813070715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Différenciation, points de vigilance et matériel sur la séquence et la séance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seance_instance ADD materials LONGTEXT DEFAULT NULL, ADD watch_points LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE seance_template ADD materials LONGTEXT DEFAULT NULL, ADD watch_points LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE sequence_instance ADD differentiation LONGTEXT DEFAULT NULL, ADD watch_points LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE sequence_template ADD differentiation LONGTEXT DEFAULT NULL, ADD watch_points LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seance_instance DROP materials, DROP watch_points');
        $this->addSql('ALTER TABLE seance_template DROP materials, DROP watch_points');
        $this->addSql('ALTER TABLE sequence_instance DROP differentiation, DROP watch_points');
        $this->addSql('ALTER TABLE sequence_template DROP differentiation, DROP watch_points');
    }
}
