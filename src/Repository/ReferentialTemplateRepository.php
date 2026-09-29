<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Referential;
use App\Entity\ReferentialTemplate;
use App\Enum\ReferentialTemplateKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferentialTemplate>
 */
class ReferentialTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferentialTemplate::class);
    }

    /**
     * The template **in service** for a session - never another year's (R15). Null means no
     * .xlsx export for that session, and the screen says so.
     */
    public function findInService(Referential $referential, ?int $session, ReferentialTemplateKind $kind = ReferentialTemplateKind::E5Synthesis): ?ReferentialTemplate
    {
        if (null === $session) {
            return null;
        }

        $template = $this->findOneBy(['referential' => $referential, 'session' => $session, 'kind' => $kind]);

        return null !== $template && $template->isInService() ? $template : null;
    }
}
