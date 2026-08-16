<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The wiki's search index (design/validated/wiki.md, "Search").
 *
 * FULLTEXT over `title` and `body_text` together, so one MATCH answers "this word is on the page"
 * whether it sits in the heading or in the text. `body_text` is the de-tagged copy of the body,
 * rebuilt on every save by App\Service\WikiBodyText: a separate column rather than a LIKE over
 * `body` so that searching for "table" finds the word rather than every page holding a table - and
 * so an index is usable at all.
 *
 * Note MySQL's innodb_ft_min_token_size (3 by default) means a two-letter word is not indexed. That
 * is the engine's, not this schema's, and searching for "ip" therefore finds nothing until an
 * administrator lowers it - a documented limit, not a bug to chase in the application.
 */
final class Version20260816072226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wiki : index FULLTEXT (titre, texte dé-balisé) pour la recherche dans un wiki';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE FULLTEXT INDEX ft_wiki_node_search ON wiki_node (title, body_text)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ft_wiki_node_search ON wiki_node');
    }
}
