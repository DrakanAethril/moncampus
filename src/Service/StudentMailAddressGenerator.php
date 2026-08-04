<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\EmailAliasRepository;

/**
 * Fabrique la partie locale « prenom.nom » de l'adresse Courrier école d'un élève.
 *
 * Distinct de App\Service\LoginGenerator, qui produit l'identifiant LDAP (`croux`) : ce dernier
 * doit rester court et aligné sur l'annuaire, alors qu'une adresse imprimée sur un CV et lue par
 * un recruteur doit être lisible. Les deux cohabitent - le login reste enregistré comme alias
 * secondaire, si bien que les deux adresses délivrent.
 *
 * Effet de bord agréable de la forme longue : elle entre *moins* souvent en collision que le
 * login. `croux` attrape Camille, Clément et Chloé Roux ; `camille.roux` ne heurte qu'un homonyme
 * complet, ce qui est rare à l'échelle d'un établissement.
 *
 * Règle de translittération, figée une bonne fois (une adresse partie chez une entreprise ne se
 * change pas) :
 * - repli ASCII et minuscules : `Chloé` → `chloe` ;
 * - tirets conservés : `Jean-Pierre` → `jean-pierre` ;
 * - espaces supprimés, ce qui colle les particules : `Le Gall` → `legall` ;
 * - apostrophes supprimées : `d'Arcy` → `darcy` ;
 * - homonymes réels départagés par un suffixe numérique à partir de 2.
 */
class StudentMailAddressGenerator
{
    /** Limite de la partie locale d'une adresse mail, RFC 5321. */
    private const int MAX_LOCAL_PART_LENGTH = 64;

    /** Borne de sécurité : au-delà, on préfère échouer bruyamment qu'itérer indéfiniment. */
    private const int MAX_COLLISION_ATTEMPTS = 99;

    /**
     * Les parties locales déjà attribuées par cette instance, en plus de celles présentes en base.
     *
     * Nécessaire parce que le contrôle d'unicité est une requête : deux homonymes traités dans un
     * même lot, avant le flush, seraient tous deux jugés libres et se heurteraient sur la
     * contrainte unique. Concerne autant la reprise par lots que le `--dry-run`, qui n'écrit rien
     * du tout et n'aurait donc jamais rien à trouver en base.
     *
     * @var array<string, true>
     */
    private array $issued = [];

    public function __construct(private readonly EmailAliasRepository $aliasRepository)
    {
    }

    /**
     * @throws \RuntimeException si l'élève n'a ni prénom ni nom exploitable, ou si toutes les
     *                          variantes numérotées sont déjà prises
     */
    public function generateFor(User $user): string
    {
        $base = $this->buildBase($user->getFirstname(), $user->getLastname());

        if ('' === $base) {
            throw new \RuntimeException(sprintf(
                'Impossible de composer une adresse pour l\'utilisateur "%s" : prénom et nom vides après translittération.',
                $user->getUsername(),
            ));
        }

        if ($this->isAvailable($base)) {
            return $this->issue($base);
        }

        for ($suffix = 2; $suffix <= self::MAX_COLLISION_ATTEMPTS; ++$suffix) {
            // Le suffixe est ajouté *après* troncature, pour qu'un nom très long ne fasse pas
            // déborder la limite en se numérotant.
            $candidate = $this->truncate($base, self::MAX_LOCAL_PART_LENGTH - \strlen((string) $suffix)).$suffix;

            if ($this->isAvailable($candidate)) {
                return $this->issue($candidate);
            }
        }

        throw new \RuntimeException(sprintf('Aucune adresse libre pour la base "%s".', $base));
    }

    /** La forme normalisée d'un login existant, pour l'enregistrer comme alias secondaire. */
    public function normalizeLoginAlias(string $login): string
    {
        return $this->truncate($this->clean($login), self::MAX_LOCAL_PART_LENGTH);
    }

    /** Réserve une partie locale décidée hors du générateur (l'alias de login, par exemple). */
    public function reserve(string $localPart): void
    {
        $this->issued[$localPart] = true;
    }

    public function isAvailable(string $localPart): bool
    {
        return !isset($this->issued[$localPart]) && !$this->aliasRepository->localPartExists($localPart);
    }

    private function issue(string $localPart): string
    {
        $this->issued[$localPart] = true;

        return $localPart;
    }

    private function buildBase(?string $firstname, ?string $lastname): string
    {
        $first = $this->clean($firstname ?? '');
        $last = $this->clean($lastname ?? '');

        // Un élève dont une seule des deux moitiés est renseignée reçoit tout de même une adresse
        // utilisable, plutôt qu'un point orphelin en tête ou en queue.
        $base = match (true) {
            '' !== $first && '' !== $last => $first.'.'.$last,
            '' !== $last => $last,
            default => $first,
        };

        return $this->truncate($base, self::MAX_LOCAL_PART_LENGTH);
    }

    private function clean(string $part): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $part);
        $lowered = mb_strtolower(false !== $ascii ? $ascii : $part);

        // Tout ce qui n'est ni lettre, ni chiffre, ni tiret disparaît : les espaces (qui collent
        // les particules) et les apostrophes tombent ici, sans traitement particulier.
        $clean = preg_replace('/[^a-z0-9-]/', '', $lowered) ?? '';

        // `Jean--Pierre` ou un tiret en bord de chaîne donneraient une adresse mal formée.
        $clean = preg_replace('/-+/', '-', $clean) ?? '';

        return trim($clean, '-');
    }

    /** Tronque sans jamais laisser un séparateur en fin d'adresse. */
    private function truncate(string $value, int $maxLength): string
    {
        if (\strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(substr($value, 0, $maxLength), '-.');
    }
}
