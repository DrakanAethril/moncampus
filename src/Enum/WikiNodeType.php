<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Folder or page - the discriminator of the single App\Entity\WikiNode table.
 *
 * One table rather than two entities because every operation the rail performs (move a page into a
 * folder, reorder siblings, walk the tree depth-first for the PDF export) would otherwise have to
 * merge and re-sort two lists. A Folder may carry a body of its own, which then renders as its
 * landing page: that covers the "everything is a page that can have children" model without a
 * second concept.
 */
enum WikiNodeType: string
{
    case Folder = 'folder';
    case Page = 'page';

    public function labelKey(): string
    {
        return match ($this) {
            self::Folder => 'wikiNodeTypeFolderLabel',
            self::Page => 'wikiNodeTypePageLabel',
        };
    }
}
