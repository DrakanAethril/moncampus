<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipTutorLink;
use App\Entity\User;

/**
 * Who one talks to at an employer, read off that employer's alternances - the UFA's « fiche
 * entreprise » (App\Controller\Ufa\EnterpriseController).
 *
 * Nothing stores a company's contacts: a contact *is* a tutor somebody named on a contract with
 * that company, which is why this groups rather than queries. Two decisions live here:
 *
 * - **a tutor stays a contact once their alternance is over.** An employer between two contracts
 *   would otherwise show no one at all, which is precisely when somebody needs to call them.
 *   Whether anything is still running is said per contact (`activeCount`) instead of filtering;
 * - **the contacts still carrying an alternance come first**, then alphabetical order, because
 *   that is who today's call is about.
 *
 * @phpstan-type EnterpriseContact array{tutor: User, alternances: list<InternshipTutorLink>, activeCount: int}
 */
class EnterpriseContacts
{
    /**
     * @param list<InternshipTutorLink> $links every alternance of one employer, terminated included
     *
     * @return list<EnterpriseContact>
     */
    public function fromLinks(array $links): array
    {
        /** @var array<int, EnterpriseContact> $contacts */
        $contacts = [];

        foreach ($links as $link) {
            $tutor = $link->getTutor();

            // A link with no tutor is not reachable through the forms, which resolve one before
            // validation runs - but the column has been nullable in PHP since that mechanism
            // existed, and a contact with no name is not a contact.
            if (!$tutor instanceof User) {
                continue;
            }

            // Grouped on the instance rather than on the id: the links all come out of one query,
            // so Doctrine's identity map already hands back a single object per tutor row, and an
            // entity that has not been persisted yet has no id to group on.
            $key = spl_object_id($tutor);
            $contacts[$key] ??= ['tutor' => $tutor, 'alternances' => [], 'activeCount' => 0];
            $contacts[$key]['alternances'][] = $link;

            if (!$link->isTerminated()) {
                ++$contacts[$key]['activeCount'];
            }
        }

        $ordered = array_values($contacts);
        usort($ordered, static function (array $a, array $b): int {
            $running = ($b['activeCount'] > 0 ? 1 : 0) <=> ($a['activeCount'] > 0 ? 1 : 0);

            return 0 !== $running
                ? $running
                : strcasecmp(
                    $a['tutor']->getDisplayName() ?? $a['tutor']->getUsername(),
                    $b['tutor']->getDisplayName() ?? $b['tutor']->getUsername(),
                );
        });

        return $ordered;
    }
}
