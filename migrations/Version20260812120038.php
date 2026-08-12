<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le média qu'un import a nommé sans pouvoir le déposer.
 *
 * Une IA ne peut pas déposer un fichier dans l'application : elle nomme celui qu'on lui a montré.
 * La question est créée quand même et marquée incomplète — le verrou est au lancement, là où un
 * média manquant casserait vraiment une passation. La colonne est distincte de image_storage_key,
 * qui est une clé d'objet déjà déposé : joindre le fichier efface l'une et renseigne l'autre.
 *
 * Colonne nullable ajoutée : aucune ligne existante n'est réécrite.
 */
final class Version20260812120038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute quiz_question.expected_media_name (le média attendu par une question importée)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD expected_media_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question DROP expected_media_name');
    }
}
