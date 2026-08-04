<?php

namespace App\Service;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Repository\EmailAliasRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Donne à un élève ses adresses Courrier école : l'adresse lisible « prenom.nom » (primaire, celle
 * qu'on affiche et depuis laquelle on écrit) et son login LDAP en alias secondaire, pour que les
 * deux formes délivrent.
 *
 * Appelé aussi bien par la reprise de l'existant (App\Command\BackfillStudentMailAliasesCommand)
 * que, plus tard, à la création d'un élève. Idempotent : un élève déjà pourvu est laissé tel quel,
 * puisqu'une adresse déjà partie chez une entreprise ne doit jamais être régénérée.
 */
class StudentMailProvisioner
{
    public function __construct(
        private readonly EmailAliasRepository $aliasRepository,
        private readonly StudentMailAddressGenerator $addressGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<EmailAlias> les alias créés lors de cet appel - vide si l'élève en avait déjà
     */
    public function provisionFor(User $user): array
    {
        if (null !== $this->aliasRepository->findPrimaryForUser($user)) {
            return [];
        }

        $created = [];

        $primary = (new EmailAlias())
            ->setUser($user)
            ->setLocalPart($this->addressGenerator->generateFor($user))
            ->setPrimary(true);

        $this->entityManager->persist($primary);
        $created[] = $primary;

        // L'alias de login n'est ajouté que s'il est libre : si un homonyme l'a déjà pris, on s'en
        // passe plutôt que d'échouer - c'est un confort, pas l'adresse de référence.
        $loginLocalPart = $this->addressGenerator->normalizeLoginAlias($user->getUsername());

        if ('' !== $loginLocalPart
            && $loginLocalPart !== $primary->getLocalPart()
            && $this->addressGenerator->isAvailable($loginLocalPart)
        ) {
            $this->addressGenerator->reserve($loginLocalPart);

            $secondary = (new EmailAlias())
                ->setUser($user)
                ->setLocalPart($loginLocalPart)
                ->setPrimary(false);

            $this->entityManager->persist($secondary);
            $created[] = $secondary;
        }

        return $created;
    }
}
