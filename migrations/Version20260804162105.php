<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260804162105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déplace la désignation de l\'adresse Courrier école principale de email_alias.is_primary vers user.primary_alias_id.';
    }

    /**
     * « Quelle est l'adresse de cet élève ? » est un fait à valeur unique portant sur l'élève, et
     * non une propriété de chacun de ses alias. Porté par un booléen réparti sur N lignes, il
     * aurait fallu le défendre par un index unique partiel - « unique parmi les lignes où
     * is_primary est vrai » - que MySQL ne sait pas exprimer : UNIQUE(is_primary) n'autoriserait
     * qu'une seule ligne principale pour tout l'établissement, et UNIQUE(user_id, is_primary)
     * interdirait à un élève d'avoir plus d'un alias secondaire, ce qui est justement l'usage
     * courant (login, adresses conservées après un changement d'état civil).
     *
     * Un pointeur nullable côté user rend l'invariant vrai par construction : une colonne ne
     * contient qu'une valeur.
     *
     * Effectuée pendant que la table est vide - aucune reprise de données n'est donc nécessaire.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias DROP is_primary');
        $this->addSql('ALTER TABLE user ADD primary_alias_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6497F292713 FOREIGN KEY (primary_alias_id) REFERENCES email_alias (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D6497F292713 ON user (primary_alias_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_alias ADD is_primary TINYINT NOT NULL');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D6497F292713');
        $this->addSql('DROP INDEX IDX_8D93D6497F292713 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP primary_alias_id');
    }
}
