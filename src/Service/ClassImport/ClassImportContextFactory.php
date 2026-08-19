<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Entity\LdapManageUser;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramStudentModality;
use App\Entity\ProgramStudentOption;
use App\Entity\User;
use App\Service\LdapManageUserRoleResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads everything App\Service\ClassImport\ClassImportAnalyzer needs out of the database, once, and
 * hands it over as primitives.
 *
 * The accounts table is folded in PHP rather than searched in SQL, because the matching key drops
 * accents, hyphens and apostrophes (NameKey) and no index can answer that. One projection of the
 * accounts, then three narrow queries over the handful of ids the file actually names - a class
 * import looks at thirty people, not at the school.
 */
class ClassImportContextFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LdapManageUserRoleResolver $roleResolver,
    ) {
    }

    /** @param list<StudentRow> $rows */
    public function build(Program $program, array $rows): ClassImportContext
    {
        $wantedNameKeys = [];
        $wantedEmails = [];
        foreach ($rows as $row) {
            $wantedNameKeys[NameKey::of($row->firstname, $row->lastname)->value] = true;
            $email = mb_strtolower(trim($row->email));
            if ('' !== $email) {
                $wantedEmails[$email] = true;
            }
        }

        $candidates = $this->candidateRows($wantedNameKeys, $wantedEmails);
        $ids = array_map(static fn (array $row): int => $row['id'], $candidates);

        $memberships = $this->memberships($ids, $program);
        $carriedValues = $this->carriedValues($ids, $program);
        $createdAt = $this->creationDates($ids);

        $accountsByNameKey = [];
        $emailOwners = [];

        foreach ($candidates as $row) {
            $id = $row['id'];
            $membership = $memberships[$id] ?? ['inDestination' => false, 'sameYear' => false, 'label' => ''];

            $account = new ExistingAccount(
                id: $id,
                login: $row['username'],
                firstname: $row['firstname'] ?? '',
                lastname: $row['lastname'] ?? '',
                userType: $this->roleResolver->resolveTypeFromRoles($row['roles']) ?? 'unknown',
                active: null === $row['inactiveDate'],
                contactEmail: $row['contactEmail'],
                inDestinationProgram: $membership['inDestination'],
                inAnotherProgramOfTheSameYear: $membership['sameYear'],
                programLabel: $membership['label'],
                createdAt: $createdAt[$id] ?? null,
                optionIds: $carriedValues[$id]['options'] ?? [],
                modalityIds: $carriedValues[$id]['modalities'] ?? [],
            );

            $key = NameKey::of($account->firstname, $account->lastname)->value;
            if (isset($wantedNameKeys[$key])) {
                $accountsByNameKey[$key][] = $account;
            }

            $email = mb_strtolower(trim($account->contactEmail ?? ''));
            if ('' !== $email && isset($wantedEmails[$email])) {
                $emailOwners[$email] = [$account->id, $this->ownerLabel($account)];
            }
        }

        return new ClassImportContext(
            programLabel: $program->getShortName(),
            testProgram: $program->isTestProgram(),
            options: $this->knownValues($program->getOptions()->toArray(), false),
            modalities: $this->knownValues($program->getModalities()->toArray(), true),
            accountsByNameKey: $accountsByNameKey,
            emailOwners: $emailOwners,
        );
    }

    /**
     * Every account whose folded name is one the file writes, or whose contact address the file
     * hands to somebody. The whole table is projected because the fold cannot be pushed into SQL;
     * the projection is deliberately narrow so that stays cheap.
     *
     * @param array<string, true> $wantedNameKeys
     * @param array<string, true> $wantedEmails
     *
     * @return list<array{id: int, username: string, firstname: ?string, lastname: ?string, roles: list<string>, inactiveDate: ?\DateTimeImmutable, contactEmail: ?string}>
     */
    private function candidateRows(array $wantedNameKeys, array $wantedEmails): array
    {
        /** @var list<array{id: int, username: string, firstname: ?string, lastname: ?string, roles: list<string>, inactiveDate: ?\DateTimeImmutable, contactEmail: ?string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT u.id, u.username, u.firstname, u.lastname, u.roles, u.inactiveDate, u.contactEmail FROM '.User::class.' u'
        )->getArrayResult();

        return array_values(array_filter($rows, static function (array $row) use ($wantedNameKeys, $wantedEmails): bool {
            if (isset($wantedNameKeys[NameKey::of($row['firstname'] ?? '', $row['lastname'] ?? '')->value])) {
                return true;
            }

            $email = mb_strtolower(trim($row['contactEmail'] ?? ''));

            return '' !== $email && isset($wantedEmails[$email]);
        }));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{inDestination: bool, sameYear: bool, label: string}>
     */
    private function memberships(array $ids, Program $program): array
    {
        if ([] === $ids) {
            return [];
        }

        // IDENTITY() and a joined id come back untyped from the driver - Doctrine only converts
        // the values of mapped fields - so every id read this way is cast rather than compared as
        // it arrives. A string "7" against an int 7 under a strict comparison is a silent miss.
        /** @var list<array{studentId: int|string, programId: int|string, shortName: string, schoolYearId: int|string|null}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT s.id AS studentId, p.id AS programId, p.shortName AS shortName, IDENTITY(p.schoolYear) AS schoolYearId'
            .' FROM '.Program::class.' p JOIN p.students s WHERE s.id IN (:ids)'
        )->setParameter('ids', $ids)->getArrayResult();

        $destinationId = $program->getId();
        $destinationYearId = $program->getSchoolYear()?->getId();

        $memberships = [];
        foreach ($rows as $row) {
            $studentId = (int) $row['studentId'];
            $current = $memberships[$studentId] ?? ['inDestination' => false, 'sameYear' => false, 'label' => ''];
            $isDestination = (int) $row['programId'] === $destinationId;
            $isSameYear = null !== $destinationYearId && null !== $row['schoolYearId'] && (int) $row['schoolYearId'] === $destinationYearId;

            $current['inDestination'] = $current['inDestination'] || $isDestination;
            $current['sameYear'] = $current['sameYear'] || (!$isDestination && $isSameYear);
            // What tells two namesakes apart on screen: the class of the same year first, since
            // that is the one the operator is looking at, otherwise whichever we know of.
            if ('' === $current['label'] || ($isSameYear && !$isDestination)) {
                $current['label'] = $row['shortName'];
            }

            $memberships[$studentId] = $current;
        }

        return $memberships;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{options: list<int>, modalities: list<int>}>
     */
    private function carriedValues(array $ids, Program $program): array
    {
        if ([] === $ids) {
            return [];
        }

        $carried = [];

        /** @var list<array{studentId: int|string, valueId: int|string}> $optionRows */
        $optionRows = $this->entityManager->createQuery(
            'SELECT IDENTITY(l.student) AS studentId, IDENTITY(l.option) AS valueId FROM '.ProgramStudentOption::class.' l'
            .' WHERE l.program = :program AND IDENTITY(l.student) IN (:ids)'
        )->setParameter('program', $program)->setParameter('ids', $ids)->getArrayResult();

        foreach ($optionRows as $row) {
            $carried[(int) $row['studentId']]['options'][] = (int) $row['valueId'];
        }

        /** @var list<array{studentId: int|string, valueId: int|string}> $modalityRows */
        $modalityRows = $this->entityManager->createQuery(
            'SELECT IDENTITY(l.student) AS studentId, IDENTITY(l.modality) AS valueId FROM '.ProgramStudentModality::class.' l'
            .' WHERE l.program = :program AND IDENTITY(l.student) IN (:ids)'
        )->setParameter('program', $program)->setParameter('ids', $ids)->getArrayResult();

        foreach ($modalityRows as $row) {
            $carried[(int) $row['studentId']]['modalities'][] = (int) $row['valueId'];
        }

        return array_map(
            static fn (array $entry): array => [
                'options' => $entry['options'] ?? [],
                'modalities' => $entry['modalities'] ?? [],
            ],
            $carried,
        );
    }

    /**
     * When the account was first asked for. App\Entity\User carries no creation date of its own;
     * the queue row that created it does, and it is the one thing that reliably tells a namesake
     * created last September from one created three years ago.
     *
     * @param list<int> $ids
     *
     * @return array<int, \DateTimeImmutable>
     */
    private function creationDates(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{userId: int|string, addedAt: string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(l.user) AS userId, MIN(l.addedAt) AS addedAt FROM '.LdapManageUser::class.' l'
            .' WHERE IDENTITY(l.user) IN (:ids) GROUP BY l.user'
        )->setParameter('ids', $ids)->getArrayResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['userId']] = new \DateTimeImmutable($row['addedAt']);
        }

        return $dates;
    }

    /**
     * @param list<Option|Modality> $nodes
     *
     * @return list<KnownValue>
     */
    private function knownValues(array $nodes, bool $modality): array
    {
        $values = [];
        foreach ($nodes as $node) {
            if (null !== $node->getInactiveDate()) {
                continue;
            }

            $aliases = [];
            foreach ([$node->getName(), $node->getShortName()] as $spelling) {
                $folded = ClassImportAnalyzer::foldValue($spelling ?? '');
                if ('' !== $folded && !\in_array($folded, $aliases, true)) {
                    $aliases[] = $folded;
                }
            }

            $id = $node->getId();
            if (null === $id || [] === $aliases) {
                continue;
            }

            $values[] = new KnownValue($id, $node->getName(), $modality, $aliases);
        }

        return $values;
    }

    private function ownerLabel(ExistingAccount $account): string
    {
        return '' !== $account->programLabel
            ? sprintf('%s (%s)', $account->login, $account->programLabel)
            : $account->login;
    }
}
