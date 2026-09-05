<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\HtmlPlainText;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `plain_text` - the words inside a piece of editor HTML, for the one-line previews screens show
 * above a full record (a lesson log's section, a shared quiz's question, a mail's body).
 *
 * Use it instead of `|striptags` on anything a HugeRTE field produced. `|striptags` drops the tags
 * and leaves the entities, so "S&eacute;ance" reaches the reader spelled out character by
 * character - the template escapes the ampersand it was handed, correctly, having no way to know
 * it was meant as an é. See App\Service\HtmlPlainText.
 *
 * The filter answers null on an empty field, so `{{ content|plain_text ?: 'nothing yet'|trans }}`
 * is the whole pattern - HugeRTE's `<p>&nbsp;</p>` counts as empty.
 *
 * `plain_text_lines` is the same thing for a body shown at length rather than in one line: it keeps
 * the document's own paragraphs as line breaks, for a `|nl2br` further down the chain.
 */
class TextExtension extends AbstractExtension
{
    public function __construct(private readonly HtmlPlainText $plainText)
    {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('plain_text', $this->plainText->fromHtml(...)),
            new TwigFilter('plain_text_lines', $this->plainText->linesFromHtml(...)),
        ];
    }
}
