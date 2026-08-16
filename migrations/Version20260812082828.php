<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Access conditions (point 3): two columns on the four hosts.
 *
 * `access_condition` is nullable and null means « no condition », so no existing row is rewritten and
 * nothing changes for what is already in the database. `access_condition_display` carries its
 * « locked » default on the MySQL side as on the entity side: that is the safe side, an unwarranted
 * greying-out is visible where an unwarranted hiding makes an assignment disappear with no diagnosis.
 */
final class Version20260812082828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conditions d\'accès : colonnes access_condition et access_condition_display sur assignment, library_resource_instance, quiz_instance et sequence_instance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
        $this->addSql('ALTER TABLE library_resource_instance ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
        $this->addSql('ALTER TABLE quiz_instance ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
        $this->addSql('ALTER TABLE sequence_instance ADD access_condition JSON DEFAULT NULL, ADD access_condition_display VARCHAR(20) DEFAULT \'locked\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment DROP access_condition, DROP access_condition_display');
        $this->addSql('ALTER TABLE library_resource_instance DROP access_condition, DROP access_condition_display');
        $this->addSql('ALTER TABLE quiz_instance DROP access_condition, DROP access_condition_display');
        $this->addSql('ALTER TABLE sequence_instance DROP access_condition, DROP access_condition_display');
    }
}
