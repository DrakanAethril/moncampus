<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coefficient de matière : poids de chaque Topic dans la moyenne générale de l'étudiant
 * (Carnet de notes), à côté du coefficient déjà porté par chaque Evaluation - voir
 * App\Service\EvaluationAverageCalculator::overallAverage().
 *
 * Reprise de l'existant : toutes les matières déjà en base partent à 1, coefficient neutre, donc
 * aucune moyenne par matière ne bouge. La moyenne *générale* change en revanche de définition
 * (moyenne pondérée des moyennes par matière, et non plus moyenne de toutes les notes mises à
 * plat) : à coefficients égaux, une matière ne pèse plus proportionnellement à son nombre
 * d'évaluations. C'est le comportement attendu d'un bulletin, pas une régression.
 */
final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute un coefficient décimal sur topic, pris en compte dans la moyenne générale.';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT 1 le temps de remplir les lignes existantes, puis retiré : le mapping Doctrine
        // ne déclare aucun default côté base (l'initialiseur PHP suffit), et le laisser ferait
        // diverger doctrine:schema:validate.
        $this->addSql('ALTER TABLE topic ADD coefficient DOUBLE PRECISION NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE topic ALTER coefficient DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE topic DROP coefficient');
    }
}
