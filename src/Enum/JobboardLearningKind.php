<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the source resolution decided on its own, on a deposit of offers.
 *
 * Two gestures, and only two, because only two of them change the table the resolution reads next
 * time (App\Service\Jobboard\JobboardSourceResolver). A host that already belongs to a known site
 * decides nothing new and is not a gesture: it is the ordinary case, and the answer already echoes
 * the retained `source` on every line.
 *
 * **Neither of them refuses anything.** They are recorded so an administrator can see them and undo
 * one if it was wrong, not so an offer can be turned away - see App\Service\Jobboard\JobboardRejection,
 * where it is said why the two refusals that used to live here were removed.
 */
enum JobboardLearningKind: string
{
    /** No known domain, no known name: the site was created from the URL's domain. */
    case SourceCreated = 'source_created';

    /**
     * The name was known, the host was not, so the host's domain was attached to that site. This is
     * the one that deserves a screen: a redirector or a shortener declared under a known name glues
     * an alien domain onto it, and every later offer from behind that domain files there silently.
     */
    case DomainAttached = 'domain_attached';

    public function labelKey(): string
    {
        return match ($this) {
            self::SourceCreated => 'jobboardLearningSourceCreatedLabel',
            self::DomainAttached => 'jobboardLearningDomainAttachedLabel',
        };
    }
}
