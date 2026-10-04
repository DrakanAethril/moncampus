<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ECF booklet: handed to the student for their signature « pour information », and that signature.
 */
final class Version20261004093047 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ECF booklet: offer to the student and candidate signature';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ecf_booklet ADD offered_at DATETIME DEFAULT NULL, ADD candidate_signed_at DATETIME DEFAULT NULL, ADD candidate_signer_name VARCHAR(160) DEFAULT NULL, ADD offered_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ecf_booklet ADD CONSTRAINT FK_CF4A45FFC68D530F FOREIGN KEY (offered_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CF4A45FFC68D530F ON ecf_booklet (offered_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ecf_booklet DROP FOREIGN KEY FK_CF4A45FFC68D530F');
        $this->addSql('DROP INDEX IDX_CF4A45FFC68D530F ON ecf_booklet');
        $this->addSql('ALTER TABLE ecf_booklet DROP offered_at, DROP candidate_signed_at, DROP candidate_signer_name, DROP offered_by_id');
    }
}
