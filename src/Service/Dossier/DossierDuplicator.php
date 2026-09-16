<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierGroup;
use App\Entity\User;

/**
 * « Dupliquer » — a dossier's composition, one year later, as a brouillon.
 *
 * **What is copied is what somebody wrote**: the title, the consignes, the groups, the documents and
 * their rules. **What is not copied is what happened**: no cible, no dépôt, no échange, no
 * validateur but the person doing the duplication. A copy carrying last year's class would be a
 * dossier addressed to students who have left, and one carrying last year's dépôts would be a lie
 * about who handed in what.
 *
 * Every date moves by exactly one year, the dossier's period included. A year is the unit this
 * gesture exists for - « le dossier de soutenance, comme l'an dernier » - and a duplicate whose
 * dates all read as past would have to be retyped line by line, which is the thing it is meant to
 * avoid. `\DateTimeImmutable::modify('+1 year')` keeps the day of the month and lands 29 February on
 * 1 March, which is the behaviour to want here: a date limite that silently moved back a day would
 * be worse.
 */
class DossierDuplicator
{
    public function duplicate(Dossier $source, User $author): Dossier
    {
        $copy = new Dossier();
        $copy->setCreatedBy($author);
        $copy->addValidator($author);
        $copy->setTitle($source->getTitle());
        $copy->setDescription($source->getDescription());
        $copy->setStartsOn($this->nextYear($source->getStartsOn()));
        $copy->setEndsOn($this->nextYear($source->getEndsOn()));

        // Keyed on the object rather than on its id: a group being duplicated normally has one, but
        // a dossier still being composed has not been flushed, and `null` keys would file every
        // document under the same group.
        /** @var array<int, DossierGroup> $groupCopies */
        $groupCopies = [];

        foreach ($source->getGroups() as $group) {
            $groupCopies[spl_object_id($group)] = new DossierGroup($copy, $group->getTitle(), $group->getPosition());
        }

        foreach ($source->getDocuments() as $document) {
            $sourceGroup = $document->getGroup();

            (new DossierDocument($copy))
                ->setName($document->getName())
                ->setGroup(null === $sourceGroup ? null : ($groupCopies[spl_object_id($sourceGroup)] ?? null))
                ->setRequired($document->isRequired())
                ->setDepositType($document->getDepositType())
                ->setVisibleFrom($this->nextYear($document->getVisibleFrom()))
                ->setDueOn($this->nextYear($document->getDueOn()))
                ->setLateAllowed($document->isLateAllowed())
                ->setValidationProfile($document->getValidationProfile())
                ->setPosition($document->getPosition())
            ;
        }

        return $copy;
    }

    private function nextYear(?\DateTimeImmutable $date): ?\DateTimeImmutable
    {
        return $date?->modify('+1 year');
    }
}
