<?php

declare(strict_types=1);

namespace App\Service\VmBatch;

use App\Entity\GroupBatch;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;

/**
 * Reads a class out of the database and flattens it into the plain values
 * App\Service\VmBatch\VmBatchPlanner works on.
 *
 * The reason for the split is the targeting. A Program *offers* options and modalities; which
 * students actually take which is a **per-student** fact, held by ProgramStudentOption and
 * ProgramStudentModality. Reading the program's own collections would answer "SIO2 offers SISR" and
 * match all twenty-four students, which is precisely the mistake that turns "eight machines for the
 * SISR group" into a full class. So each member carries their own option and modality ids, and the
 * filtering happens on those.
 */
class BatchMemberResolver
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $studentOptions,
        private readonly ProgramStudentModalityRepository $studentModalities,
    ) {
    }

    /** @return list<BatchMember> */
    public function forProgram(Program $program): array
    {
        $members = [];

        foreach ($program->getStudents() as $student) {
            $members[] = new BatchMember(
                $student->getId() ?? 0,
                $student->getDisplayName() ?? $student->getUsername(),
                $this->loginFor($student),
                $this->optionIdsFor($program, $student),
                $this->modalityIdsFor($program, $student),
            );
        }

        // Sorted by the name people read, so the machines of a batch come out in the order of the
        // list somebody is holding.
        usort($members, static fn (BatchMember $a, BatchMember $b): int => strnatcasecmp($a->displayName, $b->displayName));

        return $members;
    }

    /**
     * The groups of a saved set, each flattened into the same BatchMember values - what a PerGroup
     * batch plans from.
     *
     * **Empty groups are kept in the list**, so the caller still sees the set's own numbering; the
     * planner is what drops them. And a student who has left the class since the set was saved
     * simply does not resolve and falls out, exactly as the group-creation screen drops them when
     * it reloads a set: the set is a frozen list of ids, the class is not.
     *
     * @param string $groupTitleTemplate carries %n%, e.g. "Groupe %n%"
     *
     * @return list<array{label: string, members: list<BatchMember>}>
     */
    public function forGroupBatch(GroupBatch $groupBatch, string $groupTitleTemplate): array
    {
        $byId = [];

        foreach ($this->forProgram($groupBatch->getProgram()) as $member) {
            $byId[$member->userId] = $member;
        }

        $groups = [];

        foreach ($groupBatch->getGroups() as $index => $memberIds) {
            $members = [];

            foreach ($memberIds as $memberId) {
                if (isset($byId[$memberId])) {
                    $members[] = $byId[$memberId];
                }
            }

            $groups[] = [
                'label' => str_replace('%n%', (string) ($index + 1), $groupTitleTemplate),
                'members' => $members,
            ];
        }

        return $groups;
    }

    /**
     * People picked by hand, flattened into the single group a ForAccounts batch plans from.
     *
     * One group and not one per person: the shape is *one machine* carrying an account for each
     * name on it. Picking three people therefore builds one machine with three accounts, which is
     * the difference with PerStudent and the reason this is not a filter over a class.
     *
     * No option or modality ids: these accounts are not read out of a program, so there is nothing
     * to narrow them by - and an empty list is what the planner's filters already read as "not
     * concerned" rather than as "nobody".
     *
     * The label is the names, because it is what the batch screen shows in the machine's row and
     * "who is this machine for" is the only question that row answers. The slug is kept apart from
     * it: a hostname built out of three names is unreadable, and the machine's name is a hostname.
     *
     * @param list<User> $users
     *
     * @return list<array{label: string, slug: string, members: list<BatchMember>}>
     */
    public function forUsers(array $users): array
    {
        $members = [];

        foreach ($users as $user) {
            $members[] = new BatchMember(
                $user->getId() ?? 0,
                $user->getDisplayName() ?? $user->getUsername(),
                $this->loginFor($user),
            );
        }

        if ([] === $members) {
            return [];
        }

        usort($members, static fn (BatchMember $a, BatchMember $b): int => strnatcasecmp($a->displayName, $b->displayName));

        $names = array_map(static fn (BatchMember $member): string => $member->displayName, $members);
        $label = implode(', ', $names);

        return [[
            // 180 is the column the label lands in; cut on a whole name rather than mid-word.
            'label' => mb_strlen($label) > 170 ? mb_substr($label, 0, 169).'…' : $label,
            // One account: the person's own login names their machine, which is what somebody
            // asking for a single machine expects. Several: no name is more the machine's than
            // another, so the pattern's `{login}` falls back to a neutral word.
            'slug' => 1 === \count($members) ? $members[0]->login : 'poste',
            'members' => $members,
        ]];
    }

    /**
     * The login a student gets on their machine: **the one they already have on the platform**, read
     * as it stands and not derived from anything.
     *
     * This used to be built from their name (`marie-dupont`), on the argument that it is easier to
     * say out loud. It is not what a login is for. The platform username is what the student already
     * types to sign in, and it is unique by construction where two students with the same name are
     * not - which on a per-group machine, where several accounts sit side by side, is the difference
     * between two accounts and two people sharing one.
     *
     * Taken verbatim: a login the directory holds and MonCampus rewrote would be a third identifier
     * for the same person. What a directory can hold and useradd cannot is checked before the
     * machines are built, by VmBatchExecutor - never silently dropped.
     */
    private function loginFor(User $student): string
    {
        return $student->getUsername();
    }

    /** @return list<int> */
    private function optionIdsFor(Program $program, User $student): array
    {
        $ids = [];

        foreach ($this->studentOptions->findOptionsForStudent($program, $student) as $option) {
            $id = $option->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<int> */
    private function modalityIdsFor(Program $program, User $student): array
    {
        $ids = [];

        foreach ($this->studentModalities->findModalitiesForStudent($program, $student) as $modality) {
            $id = $modality->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
