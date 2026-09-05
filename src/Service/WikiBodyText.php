<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns a page's HTML body into the plain text App\Entity\WikiNode::$bodyText carries.
 *
 * That column exists so the search can use a FULLTEXT index at all, and so that looking for
 * "table" finds the word rather than every page holding a table. It is rebuilt on every save
 * rather than derived at query time - the read side is where a wiki is used.
 *
 * The flattening itself is App\Service\HtmlPlainText's, shared with every screen that shows a
 * preview line of the same editor's HTML: entities decoded, &nbsp; folded to an ordinary space
 * (French typography puts one before every ':' and inside guillemets, and a search for "test" must
 * match a page that wrote "«&nbsp;test&nbsp;»"). This class stays because *what the column is for*
 * is a wiki question, and only the mechanics were ever general.
 */
class WikiBodyText
{
    public function __construct(private readonly HtmlPlainText $plainText)
    {
    }

    public function fromHtml(?string $html): ?string
    {
        return $this->plainText->fromHtml($html);
    }
}
