<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three kinds of entry the help holds, all stored as App\Entity\HelpArticle rows.
 *
 * They differ by how they are *read*, not by what they contain: an Article is a step-by-step page
 * of its own, a Faq is a short answer shown folded on the help home, and a Glossary entry is one
 * term and its definition. The search screen filters on exactly these three
 * (design_handoff_aide, écran 3).
 */
enum HelpArticleKind: string
{
    case Article = 'article';
    case Faq = 'faq';
    case Glossary = 'glossary';

    public function labelKey(): string
    {
        return match ($this) {
            self::Article => 'helpKindArticleLabel',
            self::Faq => 'helpKindFaqLabel',
            self::Glossary => 'helpKindGlossaryLabel',
        };
    }

    /** Only a full article gets its own page; FAQ answers and glossary definitions are read in place. */
    public function hasOwnPage(): bool
    {
        return self::Article === $this;
    }
}
