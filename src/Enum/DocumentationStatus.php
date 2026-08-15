<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where an App\Entity\DocumentationArticle stands in its life (handoff 2d, "Statut").
 *
 * Draft is the default and is deliberately not auto-saved: an article exists as a draft only
 * because somebody saved it as one. Only Published is ever readable by its audience, and even
 * then within its diffusion window - see App\Service\DocumentationAccess.
 */
enum DocumentationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'documentationStatusDraftLabel',
            self::Published => 'documentationStatusPublishedLabel',
            self::Archived => 'documentationStatusArchivedLabel',
        };
    }
}
