<?php

namespace App\Repository;

use App\Entity\GroupType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupType>
 */
class GroupTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupType::class);
    }

    // Powers the groupType picker on Group's own new/edit form, and the "group by type" chip
    // display on the user creation form (App\Form\LdapManageUserType) - active types only,
    // ordered by the drag-and-drop position set on /settings/groups/types.
    /** @return list<GroupType> */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.inactiveDate IS NULL')
            ->orderBy('t.order', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Full list (active + inactive), ordered by position - the canonical order used both to
    // render the reorderable settings list and, re-fetched, to safely resolve a POSTed reorder
    // (see SettingsGroupsController::reorderGroupTypes()).
    /** @return list<GroupType> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.order', 'ASC')
            ->getQuery()
            ->getResult();
    }

}
