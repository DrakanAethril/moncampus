<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every help entry the language it is written in.
 *
 * A translation is a second row sharing the slug, not a field on the first one, so the uniqueness
 * of a slug becomes per language - hence the two indexes rebuilt below. Which row a reader gets is
 * decided at read time by App\Service\HelpLocaleResolver.
 *
 * The column arrives with a DEFAULT 'fr' that is dropped straight after: rows already in the table
 * were all written in French, and MySQL has nothing else to put in a NOT NULL column being added to
 * a table that is not empty. The default is not kept, so the schema still matches the mapping.
 */
final class Version20260810033033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Aide : langue de rédaction sur les rubriques et les articles, slug unique par langue";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE help_article ADD locale VARCHAR(5) DEFAULT 'fr' NOT NULL");
        $this->addSql('ALTER TABLE help_article ALTER locale DROP DEFAULT');
        $this->addSql('DROP INDEX uniq_help_article_slug ON help_article');
        $this->addSql('CREATE UNIQUE INDEX uniq_help_article_slug ON help_article (section_id, slug, locale)');

        $this->addSql("ALTER TABLE help_section ADD locale VARCHAR(5) DEFAULT 'fr' NOT NULL");
        $this->addSql('ALTER TABLE help_section ALTER locale DROP DEFAULT');
        $this->addSql('DROP INDEX uniq_help_section_slug ON help_section');
        $this->addSql('CREATE UNIQUE INDEX uniq_help_section_slug ON help_section (slug, locale)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_help_article_slug ON help_article');
        $this->addSql('ALTER TABLE help_article DROP locale');
        $this->addSql('CREATE UNIQUE INDEX uniq_help_article_slug ON help_article (section_id, slug)');

        $this->addSql('DROP INDEX uniq_help_section_slug ON help_section');
        $this->addSql('ALTER TABLE help_section DROP locale');
        $this->addSql('CREATE UNIQUE INDEX uniq_help_section_slug ON help_section (slug)');
    }
}
