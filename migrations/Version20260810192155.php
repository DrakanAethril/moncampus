<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un quiz lancé peut désormais fusionner plusieurs quiz en un seul vivier de questions (les cinq
 * quiz de séance devenant l'évaluation de fin de séquence) : la provenance passe donc d'une seule
 * colonne à une table de liaison. `source_template_id` reste en place et désigne le quiz d'où le
 * lancement est parti.
 */
final class Version20260810192155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz : table de liaison des quiz sources d\'une instance (vivier fusionné)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quiz_instance_source_template (quiz_instance_id INT NOT NULL, quiz_template_id INT NOT NULL, INDEX IDX_E88CBB77157761BD (quiz_instance_id), INDEX IDX_E88CBB772AFC1C18 (quiz_template_id), PRIMARY KEY (quiz_instance_id, quiz_template_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_instance_source_template ADD CONSTRAINT FK_E88CBB77157761BD FOREIGN KEY (quiz_instance_id) REFERENCES quiz_instance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_instance_source_template ADD CONSTRAINT FK_E88CBB772AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id) ON DELETE CASCADE');

        // Les instances déjà lancées ont un seul quiz source : on la reporte, sinon leur provenance
        // se lirait comme vide alors qu'elle est connue. Celles dont le quiz a été supprimé depuis
        // (source_template_id NULL) n'ont rien à reporter.
        $this->addSql('INSERT INTO quiz_instance_source_template (quiz_instance_id, quiz_template_id) SELECT id, source_template_id FROM quiz_instance WHERE source_template_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_instance_source_template DROP FOREIGN KEY FK_E88CBB77157761BD');
        $this->addSql('ALTER TABLE quiz_instance_source_template DROP FOREIGN KEY FK_E88CBB772AFC1C18');
        $this->addSql('DROP TABLE quiz_instance_source_template');
    }
}
