<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260804164305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l\'origine d\'un alias Courrier école (generated/login/manual), qui décide des règles de forme et de son caractère administrable.';
    }

    /**
     * L'origine porte deux règles à la fois : un alias saisi à la main doit contenir un point
     * (`quelquechose.quelquechose`) et il est le seul administrable depuis l'application.
     *
     * Le point n'est pas cosmétique. La réception étant en catch-all, créer un alias revient à
     * fabriquer une identité d'expédition sur le domaine de l'établissement : sans cette règle,
     * `comptabilite@` ou `direction@` seraient indiscernables d'une adresse officielle pour
     * l'entreprise qui les reçoit, alors qu'elles pointent vers la boîte d'un élève.
     *
     * L'alias de login (`croux`) est la seule exception - il n'a jamais de point parce qu'il
     * reprend l'identifiant de l'annuaire et n'est saisi par personne.
     *
     * Colonne ajoutée nullable puis remplie avant d'être rendue obligatoire : la base de
     * développement porte déjà les alias de la reprise, et un ADD ... NOT NULL sans défaut les
     * remplirait d'une chaîne vide.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias ADD origin VARCHAR(20) DEFAULT NULL');

        // Le provisionnement ne pose que deux origines : l'adresse principale de l'élève est celle
        // composée depuis l'état civil, toutes les autres sont son login.
        $this->addSql('UPDATE email_alias a JOIN `user` u ON u.primary_alias_id = a.id SET a.origin = \'generated\'');
        $this->addSql('UPDATE email_alias SET origin = \'login\' WHERE origin IS NULL');

        $this->addSql('ALTER TABLE email_alias MODIFY origin VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias DROP origin');
    }
}
