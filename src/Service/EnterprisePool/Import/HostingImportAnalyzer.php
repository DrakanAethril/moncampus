<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool\Import;

use App\Entity\Enterprise;
use App\Entity\EnterpriseHosting;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\HostingKind;
use App\Repository\UserRepository;
use App\Service\EnterprisePool\EnterpriseHostings;
use App\Service\Sirene\Siret;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\String\u;

/**
 * The « analyse à blanc » of « Importer l'historique » (design/validated/vivier-entreprises.md §7.2):
 * every line resolved - its type, its year, its filière, its student, its company - and judged,
 * without writing anything. One blocking finding refuses the whole file, like the contract
 * import: a file half imported is the state nobody can reason about.
 *
 * How a company is found, and the line says which way it went:
 *   1. by SIRET, if the column holds a valid one - **recorded, never confirmed** (R1): it joins
 *      the SIRET queue like any number nobody looked at;
 *   2. else by exact name (and postcode, when given) among the employers of the vivier;
 *   3. else it is created, without SIRET - « à confirmer » as well.
 *
 * Skipped with a warning rather than refused: an alternance the UFA already holds (R3 - its
 * contract says it), and a line the vivier already has.
 */
class HostingImportAnalyzer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly EnterpriseHostings $hostings,
    ) {
    }

    /**
     * @param list<HostingImportRow> $rows
     */
    public function analyze(array $rows, User $viewer): HostingImportAnalysis
    {
        $tracks = $this->indexByName($this->entityManager->getRepository(Track::class)->findAll());
        $options = $this->indexByName($this->entityManager->getRepository(Option::class)->findAll());
        $enterprises = $this->visibleEnterprises($viewer);
        $byName = [];
        foreach ($enterprises as $enterprise) {
            $byName[$this->fold($enterprise->getName())][] = $enterprise;
        }

        $lines = [];
        $seenInFile = [];
        foreach ($rows as $row) {
            $line = new HostingImportLine($row);

            $line->kind = match ($this->fold($row->get('type'))) {
                'stage' => HostingKind::Stage,
                'alternance' => HostingKind::Alternance,
                default => null,
            };
            if (null === $line->kind) {
                $line->error('enterpriseImportKindError', ['%value%' => $row->get('type')]);
            }

            $line->yearStart = $this->year($row->get('annee'));
            if (null === $line->yearStart) {
                $line->error('enterpriseImportYearError', ['%value%' => $row->get('annee')]);
            }

            $line->track = $tracks[$this->fold($row->get('filiere'))] ?? null;
            if (null === $line->track) {
                $line->error('enterpriseImportTrackError', ['%value%' => $row->get('filiere')]);
            }

            if ('' !== $row->get('option')) {
                $line->option = $options[$this->fold($row->get('option'))] ?? null;
                if (null === $line->option) {
                    $line->warning('enterpriseImportOptionWarning', ['%value%' => $row->get('option')]);
                }
            }

            $this->resolveStudent($line, $viewer);
            $this->resolveEnterprise($line, $byName);

            if ('' === $row->get('entreprise') && null === $line->enterprise) {
                $line->error('enterpriseImportEnterpriseError', []);
            }

            $key = implode('|', [$line->enterpriseKey(), $line->kind?->value, $line->yearStart, $line->student?->getId() ?? $this->fold((string) $line->studentName)]);
            if (isset($seenInFile[$key])) {
                $line->skip('enterpriseImportDuplicateInFileWarning', ['%line%' => (string) $seenInFile[$key]]);
            }
            $seenInFile[$key] = $row->line;

            $this->checkAlreadyKnown($line, $viewer);
            $lines[] = $line;
        }

        return new HostingImportAnalysis($lines);
    }

    private function resolveStudent(HostingImportLine $line, User $viewer): void
    {
        $raw = $line->row->get('etudiant');
        if ('' === $raw) {
            $line->error('enterpriseImportStudentError', []);

            return;
        }

        $account = $this->users->findOneBy(['username' => $raw]);
        if ($account instanceof User && (!$viewer->isTestUser() || $account->isTestUser())) {
            $line->student = $account;

            return;
        }

        $folded = $this->fold($raw);
        $matches = array_values(array_filter(
            $this->users->searchStudents(explode(' ', $raw)[0], 50, $viewer),
            fn (User $user): bool => $this->fold(($user->getFirstname() ?? '').' '.($user->getLastname() ?? '')) === $folded
                || $this->fold(($user->getLastname() ?? '').' '.($user->getFirstname() ?? '')) === $folded,
        ));

        if (1 === \count($matches)) {
            $line->student = $matches[0];

            return;
        }

        $line->studentName = mb_substr($raw, 0, 255);
        $line->warning('enterpriseImportStudentNameWarning', ['%value%' => $raw]);
    }

    /**
     * @param array<string, list<Enterprise>> $byName
     */
    private function resolveEnterprise(HostingImportLine $line, array $byName): void
    {
        $line->enterpriseName = mb_substr($line->row->get('entreprise'), 0, 255);
        $postalCode = $line->row->get('code_postal');
        $siretRaw = $line->row->get('siret');

        if ('' !== $siretRaw) {
            $siret = Siret::normalize($siretRaw);
            if (Siret::isValid($siret)) {
                $line->siret = $siret;
                $existing = $this->entityManager->getRepository(Enterprise::class)->findOneBy(['siret' => $siret, 'inactiveDate' => null]);
                if ($existing instanceof Enterprise) {
                    $line->enterprise = $existing;
                    $line->enterpriseVerdict = HostingImportLine::POOL_BY_SIRET;

                    return;
                }
                $line->enterpriseVerdict = HostingImportLine::NEW_WITH_SIRET;

                return;
            }
            $line->warning('enterpriseImportSiretWarning', ['%value%' => $siretRaw]);
        }

        $candidates = $byName[$this->fold($line->enterpriseName)] ?? [];
        if ('' !== $postalCode && \count($candidates) > 1) {
            $candidates = array_values(array_filter($candidates, static fn (Enterprise $enterprise): bool => $enterprise->getPostalCode() === $postalCode
                || str_contains((string) $enterprise->getAddress(), $postalCode)));
        }
        if (1 === \count($candidates)) {
            $line->enterprise = $candidates[0];
            $line->enterpriseVerdict = HostingImportLine::POOL_BY_NAME;

            return;
        }

        $line->enterpriseVerdict = HostingImportLine::NEW_WITHOUT_SIRET;
    }

    /** R3 and the vivier's own rows: what is already known is skipped, and said. */
    private function checkAlreadyKnown(HostingImportLine $line, User $viewer): void
    {
        if (null === $line->enterprise || null === $line->kind || null === $line->yearStart) {
            return;
        }

        if (HostingKind::Alternance === $line->kind && null !== $line->student) {
            foreach ($this->entityManager->getRepository(InternshipTutorLink::class)->findBy(['enterprise' => $line->enterprise, 'student' => $line->student]) as $link) {
                if ($this->hostings->yearOf($link) === $line->yearStart) {
                    $line->skip('enterpriseImportAlreadyInUfaWarning', []);

                    return;
                }
            }
        }

        foreach ($this->entityManager->getRepository(EnterpriseHosting::class)->findBy([
            'enterprise' => $line->enterprise, 'kind' => $line->kind, 'yearStart' => $line->yearStart, 'inactiveDate' => null,
        ]) as $hosting) {
            $same = null !== $line->student
                ? $hosting->getStudent() === $line->student
                : $this->fold((string) $hosting->getStudentName()) === $this->fold((string) $line->studentName);
            if ($same) {
                $line->skip('enterpriseImportAlreadyInPoolWarning', []);

                return;
            }
        }
    }

    /** « 2023-2024 », « 2023/24 », « 2023 » - the year a school year starts in. */
    private function year(string $raw): ?int
    {
        if (1 !== preg_match('/^\s*(\d{4})(?:\s*[-\/]\s*(\d{2,4}))?\s*$/', $raw, $match)) {
            return null;
        }
        $year = (int) $match[1];

        return $year >= 1990 && $year <= 2100 ? $year : null;
    }

    /**
     * @template T of Track|Option
     *
     * @param array<T> $nodes
     *
     * @return array<string, T> by folded name and short name
     */
    private function indexByName(array $nodes): array
    {
        $index = [];
        foreach ($nodes as $node) {
            $index[$this->fold($node->getName())] ??= $node;
            if ($node instanceof Option && '' !== $node->getShortName()) {
                $index[$this->fold($node->getShortName())] ??= $node;
            }
        }

        return $index;
    }

    /** @return list<Enterprise> */
    private function visibleEnterprises(User $viewer): array
    {
        $builder = $this->entityManager->createQueryBuilder()->select('e')->from(Enterprise::class, 'e')->where('e.inactiveDate IS NULL');
        if ($viewer->isTestUser()) {
            $builder->andWhere('e.testEnterprise = true');
        }

        return $builder->getQuery()->getResult();
    }

    public function fold(string $value): string
    {
        return u($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
