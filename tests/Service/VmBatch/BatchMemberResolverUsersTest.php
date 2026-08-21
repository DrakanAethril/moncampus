<?php

declare(strict_types=1);

namespace App\Tests\Service\VmBatch;

use App\Entity\User;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Service\VmBatch\BatchMemberResolver;
use PHPUnit\Framework\TestCase;

/**
 * People picked by hand, turned into the single group a « une machine pour des comptes choisis »
 * batch plans from.
 *
 * The shape's whole meaning is in the word *single*: three people picked build **one** machine
 * carrying three accounts, not three machines. Planning them as one group is what says so, and it
 * is why this shape needed no planner of its own.
 */
class BatchMemberResolverUsersTest extends TestCase
{
    public function testThreePeopleMakeOneMachineCarryingThreeAccounts(): void
    {
        $groups = $this->resolver()->forUsers([$this->user('celia.l', 'Célia', 'Lambert'), $this->user('ana.r', 'Ana', 'Roux')]);

        self::assertCount(1, $groups, 'one machine, not one per person');
        self::assertCount(2, $groups[0]['members']);
        self::assertSame(['ana.r', 'celia.l'], array_column($groups[0]['members'], 'login'));
    }

    /** The login is the platform's, verbatim - the same rule as a class roster's. */
    public function testTheLoginIsThePlatformUsername(): void
    {
        $groups = $this->resolver()->forUsers([$this->user('celia.l', 'Célia', 'Lambert')]);

        self::assertSame('celia.l', $groups[0]['members'][0]->login);
        self::assertSame('Célia Lambert', $groups[0]['members'][0]->displayName);
    }

    /** The row on the batch screen answers "who is this machine for", so the label is the names. */
    public function testTheLabelIsWhoTheMachineIsFor(): void
    {
        $groups = $this->resolver()->forUsers([$this->user('celia.l', 'Célia', 'Lambert'), $this->user('ana.r', 'Ana', 'Roux')]);

        self::assertSame('Ana Roux, Célia Lambert', $groups[0]['label']);
    }

    /**
     * A hostname built out of three names is unreadable, and the machine's name *is* its hostname -
     * so the slug is kept apart from the label. One person is the case where their own login is the
     * obvious name for their machine.
     */
    public function testTheSlugIsTheirLoginAloneAndANeutralWordTogether(): void
    {
        self::assertSame('celia.l', $this->resolver()->forUsers([$this->user('celia.l', 'Célia', 'Lambert')])[0]['slug']);
        self::assertSame(
            'poste',
            $this->resolver()->forUsers([$this->user('celia.l', 'Célia', 'Lambert'), $this->user('ana.r', 'Ana', 'Roux')])[0]['slug'],
        );
    }

    /** The label lands in a 180-character column; a class's worth of names would not fit. */
    public function testALongListOfNamesIsCutRatherThanRefused(): void
    {
        $users = [];
        foreach (range(1, 30) as $index) {
            $users[] = $this->user('etu'.$index, 'Prénom'.$index, 'NomDeFamilleAssezLong'.$index);
        }

        $label = $this->resolver()->forUsers($users)[0]['label'];

        self::assertLessThanOrEqual(180, mb_strlen($label));
        self::assertStringEndsWith('…', $label);
    }

    public function testNobodyChosenPlansNothing(): void
    {
        self::assertSame([], $this->resolver()->forUsers([]));
    }

    private function resolver(): BatchMemberResolver
    {
        // Neither repository is reached on this path: these accounts are not read out of a program,
        // so there is no per-student option or modality to look up.
        return new BatchMemberResolver(
            $this->createStub(ProgramStudentOptionRepository::class),
            $this->createStub(ProgramStudentModalityRepository::class),
        );
    }

    private function user(string $username, string $firstname, string $lastname): User
    {
        $user = new User($username);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);

        return $user;
    }
}
