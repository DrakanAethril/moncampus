<?php

declare(strict_types=1);

namespace App\Service\Accommodation;

use App\Entity\Accommodation;
use App\Entity\User;

/**
 * « What does this account's accommodations come to, right now? ».
 *
 * The one door between App\Entity\User::$accommodations and everything that acts on them. It is
 * also where a row deactivated in Paramètres > Configuration > Aménagements stops counting: the
 * link is kept on the account (it is a fact about last year as much as about this one), the effect
 * is not.
 *
 * Deliberately without a memo: the container survives the request in worker mode, and a service
 * that remembers a profile without implementing ResetInterface answers the next request with the
 * previous student's. Doctrine already hydrates the collection once per request, which is the only
 * cache this needs.
 */
class AccommodationResolver
{
    public function forUser(?User $user): AccommodationProfile
    {
        if (null === $user) {
            return AccommodationProfile::none();
        }

        return AccommodationProfile::fromAccommodations(
            $user->getAccommodations()->filter(
                static fn (Accommodation $accommodation): bool => null === $accommodation->getInactiveDate(),
            ),
        );
    }
}
