<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Matière coefficient: the weight of each Topic in the student's overall average
 * (Carnet de notes), alongside the coefficient each Evaluation already carries - see
 * App\Service\EvaluationAverageCalculator::overallAverage().
 *
 * Migration of the existing data: every matière already in the database starts at 1, a neutral
 * coefficient, so no per-matière average moves. The *overall* average, however, changes definition
 * (a weighted average of the per-matière averages, and no longer an average of every grade
 * flattened): at equal coefficients, a matière no longer weighs in proportion to its number of
 * evaluations. That is the expected behavior of a report card, not a regression.
 */
final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute un coefficient décimal sur topic, pris en compte dans la moyenne générale.';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT 1 long enough to fill the existing rows, then dropped: the Doctrine mapping
        // declares no default on the database side (the PHP initialiser is enough), and leaving it
        // would make doctrine:schema:validate diverge.
        $this->addSql('ALTER TABLE topic ADD coefficient DOUBLE PRECISION NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE topic ALTER coefficient DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE topic DROP coefficient');
    }
}
