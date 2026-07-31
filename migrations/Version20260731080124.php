<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731080124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the optional teacher a topic group is assigned to (TopicGroup::$teacher).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE topic_group ADD teacher_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE topic_group ADD CONSTRAINT FK_D6F3E8A741807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_D6F3E8A741807E1D ON topic_group (teacher_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE topic_group DROP FOREIGN KEY FK_D6F3E8A741807E1D');
        $this->addSql('DROP INDEX IDX_D6F3E8A741807E1D ON topic_group');
        $this->addSql('ALTER TABLE topic_group DROP teacher_id');
    }
}
