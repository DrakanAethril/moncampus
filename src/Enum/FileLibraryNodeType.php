<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Folder or file, in one table (design/validated/file-library.md, "Why one table for folders and
 * files").
 *
 * The same reason the wiki gives for its own two kinds: moving a file into a folder, walking the
 * tree for a breadcrumb, listing what a folder holds are all one-list operations with a
 * discriminator, and two-list merges without one.
 *
 * A folder here is deliberately poorer than a wiki folder - it carries no body, it is only a
 * container.
 */
enum FileLibraryNodeType: string
{
    case Folder = 'folder';
    case File = 'file';

    public function isFolder(): bool
    {
        return self::Folder === $this;
    }
}
