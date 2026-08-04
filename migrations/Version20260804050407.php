<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouveau type de question « Texte à trous » (design_handoff_quiz, écrans 2a-2d).
 *
 * Toute la définition d'une question à trous tient dans `blanks_config` : le texte lui-même reste
 * dans `label` (les trous y sont écrits « ... »), et le JSON porte le mode, les variantes acceptées
 * par trou, les intrus et les deux tolérances. Aucune ligne `quiz_answer` n'est créée pour ce type.
 *
 * Reprise de l'existant : c'est le seul type à noter partiellement, d'où `quiz_attempt_answer.score`
 * et le passage de `quiz_attempt.correct_count` en décimal. Les tentatives déjà terminées gardent
 * exactement leur note (un entier reste un entier en NUMERIC(7,2)), et leurs réponses reçoivent le
 * score 1/0 qui découle de `is_correct` — sans ce backfill, la somme des scores d'une ancienne
 * tentative recalculée vaudrait 0.
 */
final class Version20260804050407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Question « texte à trous » : définition JSON, réponses par trou et notation partielle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD blanks_config JSON DEFAULT NULL, ADD points NUMERIC(5, 2) DEFAULT \'1.00\' NOT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question ADD blanks_config JSON DEFAULT NULL, ADD points NUMERIC(5, 2) DEFAULT \'1.00\' NOT NULL');
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD score NUMERIC(5, 2) DEFAULT NULL, ADD blank_responses JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_attempt CHANGE correct_count correct_count NUMERIC(7, 2) DEFAULT NULL');

        $this->addSql('UPDATE quiz_attempt_answer SET score = IF(is_correct = 1, 1.00, 0.00) WHERE is_correct IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question DROP blanks_config, DROP points');
        $this->addSql('ALTER TABLE quiz_instance_question DROP blanks_config, DROP points');
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP score, DROP blank_responses');
        // Les notes partielles éventuelles sont tronquées : NUMERIC -> INT ne peut pas les garder.
        $this->addSql('ALTER TABLE quiz_attempt CHANGE correct_count correct_count INT DEFAULT NULL');
    }
}
