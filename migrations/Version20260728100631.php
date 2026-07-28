<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260728100631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add LaptopConditionType::$orderIndex (screen 25c drag-reorder, drives lend/return form option order).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop_condition_type ADD order_index INT DEFAULT 0 NOT NULL');

        // Give existing rows a stable, distinct starting order (creation order) instead of
        // leaving them all tied at the column default - same reasoning as any "add an order
        // column to pre-existing rows" migration in this codebase. Two separate statements -
        // addSql() runs each string as its own query, no multi-statement support.
        $this->addSql('SET @rank := -1');
        $this->addSql('UPDATE laptop_condition_type SET order_index = (@rank := @rank + 1) ORDER BY id ASC');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop_condition_type DROP order_index');
    }
}
