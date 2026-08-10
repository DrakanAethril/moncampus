<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the "was this article useful?" counters.
 *
 * The design handoff removed the vote block from every article page in its second iteration, so the
 * two columns had no way of ever being written to again. A column nothing feeds is worse than no
 * column: the next reader assumes it holds something.
 *
 * "Les plus consultés" on the help home is unaffected - it ranks on view_count, which is still
 * incremented when an article is opened.
 */
final class Version20260810061121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Aide : suppression des compteurs de vote « cet article vous a-t-il aidé ? »";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE help_article DROP helpful_yes_count, DROP helpful_no_count');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE help_article ADD helpful_yes_count INT NOT NULL, ADD helpful_no_count INT NOT NULL');
    }
}
