<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmDossier;
use App\Entity\CrmOrigin;
use App\Repository\CrmOriginRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réglages › Origines: the list a dossier's origins are ticked in, in its order. Renaming an
 * origin renames it in every dossier (they point at it); one a dossier carries is never deleted.
 * It never flushes.
 */
final readonly class CrmOriginEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CrmOriginRepository $origins,
    ) {
    }

    /** @return list<CrmOrigin> */
    public function all(): array
    {
        return $this->origins->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** @return array<int, int> origin id => dossiers carrying it */
    public function usage(): array
    {
        /** @var list<array{id: int, n: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o.id AS id', 'COUNT(d.id) AS n')
            ->from(CrmDossier::class, 'd')
            ->join('d.origins', 'o')
            ->groupBy('o.id')
            ->getQuery()
            ->getArrayResult();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row['id']] = (int) $row['n'];
        }

        return $usage;
    }

    /** @throws CrmRefusal */
    public function add(string $label): CrmOrigin
    {
        $label = trim($label);
        $this->checkFree($label, null);
        $all = $this->all();
        $origin = new CrmOrigin($label, [] === $all ? 1 : $all[\count($all) - 1]->getPosition() + 1);
        $this->entityManager->persist($origin);

        return $origin;
    }

    /** @throws CrmRefusal */
    public function rename(CrmOrigin $origin, string $label): void
    {
        $label = trim($label);
        $this->checkFree($label, $origin);
        $origin->setLabel($label);
    }

    public function move(CrmOrigin $origin, int $direction): void
    {
        $all = $this->all();
        $index = array_search($origin, $all, true);
        if (false === $index) {
            return;
        }
        $other = $all[$index + ($direction < 0 ? -1 : 1)] ?? null;
        if (null === $other) {
            return;
        }
        // Positions are rewritten whole, so that two equal ones never block a move.
        $all[$index] = $other;
        $all[$index + ($direction < 0 ? -1 : 1)] = $origin;
        foreach (array_values($all) as $position => $row) {
            $row->setPosition($position + 1);
        }
    }

    /** @throws CrmRefusal */
    public function delete(CrmOrigin $origin): void
    {
        if (($this->usage()[(int) $origin->getId()] ?? 0) > 0) {
            throw CrmRefusal::because('crmRefusalOriginUsed', ['%origin%' => $origin->getLabel()]);
        }
        $this->entityManager->remove($origin);
    }

    /** @throws CrmRefusal */
    private function checkFree(string $label, ?CrmOrigin $self): void
    {
        if ('' === $label) {
            throw CrmRefusal::because('crmRefusalOriginLabelMissing');
        }
        foreach ($this->all() as $origin) {
            if ($origin !== $self && mb_strtolower($origin->getLabel()) === mb_strtolower($label)) {
                throw CrmRefusal::because('crmRefusalOriginExists', ['%origin%' => $label]);
            }
        }
    }
}
