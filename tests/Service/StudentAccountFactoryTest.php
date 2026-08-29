<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LdapManageUser;
use App\Entity\UserLogin;
use App\Repository\UserLoginRepository;
use App\Service\ContactEmailVerifier;
use App\Service\LdapManageUserRoleResolver;
use App\Service\LoginGenerator;
use App\Service\NewAccountRequest;
use App\Service\StudentAccountFactory;
use App\Service\StudentMailProvisioner;
use App\Service\UserLoginHistory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StudentAccountFactoryTest extends TestCase
{
    private LoginGenerator $loginGenerator;
    private ContactEmailVerifier $contactEmailVerifier;
    private StudentMailProvisioner $mailProvisioner;
    private EntityManagerInterface $entityManager;
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];

        $loginGenerator = $this->createStub(LoginGenerator::class);
        $loginGenerator->method('generate')->willReturn('mdupont');
        $this->loginGenerator = $loginGenerator;

        $this->contactEmailVerifier = $this->createStub(ContactEmailVerifier::class);
        $this->mailProvisioner = $this->createStub(StudentMailProvisioner::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback($this->recordPersist());
        $this->entityManager = $entityManager;
    }

    public function testBuildsTheUserTheDirectoryWillLaterMirror(): void
    {
        $account = $this->factory()->create($this->request());

        self::assertSame('mdupont', $account->user->getUsername());
        self::assertSame('mdupont@beaupeyrat.lan', $account->user->getEmail());
        self::assertSame('Martin', $account->user->getFirstname());
        self::assertSame('Dupont', $account->user->getLastname());
        self::assertSame('martin@example.org', $account->user->getContactEmail());
        self::assertSame('0102030405', $account->user->getPhoneNumber());
        self::assertTrue($account->user->isMustChangePassword());
        self::assertFalse($account->user->isTestUser());
    }

    public function testDerivesTheRolesFromTheTypeAndTheGroups(): void
    {
        $account = $this->factory()->create($this->request(groups: ['sio-2', 'slam']));

        self::assertContains('ROLE_STUDENT', $account->user->getRoles());
        self::assertContains('ROLE_SIO-2', $account->user->getRoles());
        self::assertContains('ROLE_SLAM', $account->user->getRoles());
    }

    // Staff creating the account is trusted outright: no confirmation mail is ever sent, from
    // either path.
    public function testMarksTheContactAddressVerifiedWithoutMailingAnyone(): void
    {
        $verifier = $this->createMock(ContactEmailVerifier::class);
        $verifier->expects(self::once())->method('markVerifiedByStaff');
        $this->contactEmailVerifier = $verifier;

        $this->factory()->create($this->request());
    }

    public function testDoesNotMarkAnAddressThatWasNeverGiven(): void
    {
        $verifier = $this->createMock(ContactEmailVerifier::class);
        $verifier->expects(self::never())->method('markVerifiedByStaff');
        $this->contactEmailVerifier = $verifier;

        $account = $this->factory()->create($this->request(contactEmail: null));

        self::assertNull($account->user->getContactEmail());
    }

    public function testAnEmptyAddressIsNoAddress(): void
    {
        $account = $this->factory()->create($this->request(contactEmail: '  '));

        self::assertNull($account->user->getContactEmail());
    }

    public function testQueuesTheDirectoryCreation(): void
    {
        $account = $this->factory()->create($this->request(groups: ['sio-2', 'slam']));

        self::assertNotNull($account->directoryRequest);
        self::assertSame('account_create', $account->directoryRequest->getActionType());
        self::assertSame('student', $account->directoryRequest->getUserType());
        self::assertSame('Martin', $account->directoryRequest->getFirstname());
        self::assertSame('Dupont', $account->directoryRequest->getLastname());
        self::assertSame('sio-2|slam', $account->directoryRequest->getUserGroups());
        self::assertSame('mdupont', $account->directoryRequest->getLogin());
        self::assertSame($account->user, $account->directoryRequest->getUser());
        self::assertSame('sbouby', $account->directoryRequest->getAddedBy());
    }

    // The caller owns the transaction: the import writes thirty accounts or none.
    public function testPersistsTheAccountAndItsQueueRowWithoutFlushing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $entityManager->method('persist')->willReturnCallback($this->recordPersist());
        $this->entityManager = $entityManager;

        $account = $this->factory()->create($this->request());

        self::assertContains($account->user, $this->persisted);
        self::assertContains($account->directoryRequest, $this->persisted);
    }

    public function testGivesAStudentTheirSchoolMailAddresses(): void
    {
        $provisioner = $this->createMock(StudentMailProvisioner::class);
        $provisioner->expects(self::once())->method('provisionFor');
        $this->mailProvisioner = $provisioner;

        $account = $this->factory()->create($this->request());

        self::assertFalse($account->schoolMailFailed);
    }

    public function testOnlyStudentsGetSchoolMailAddresses(): void
    {
        $provisioner = $this->createMock(StudentMailProvisioner::class);
        $provisioner->expects(self::never())->method('provisionFor');
        $this->mailProvisioner = $provisioner;

        $this->factory()->create($this->request(userType: 'teacher'));
    }

    // A civil status that transliterates to nothing is no reason to refuse the account - it is
    // created without an address, and the caller is told so it can say it.
    public function testAnUnprovisionableAddressDoesNotRefuseTheAccount(): void
    {
        $provisioner = $this->createStub(StudentMailProvisioner::class);
        $provisioner->method('provisionFor')->willThrowException(new \RuntimeException('no local part left'));
        $this->mailProvisioner = $provisioner;

        $account = $this->factory()->create($this->request());

        self::assertTrue($account->schoolMailFailed);
        self::assertContains($account->user, $this->persisted);
    }

    // A demonstration account has no Windows session and nothing to receive: reception being
    // catch-all, an address exists as soon as the row does.
    public function testAnAccountOutsideTheDirectoryGetsNoQueueRowAndNoMailbox(): void
    {
        $provisioner = $this->createMock(StudentMailProvisioner::class);
        $provisioner->expects(self::never())->method('provisionFor');
        $this->mailProvisioner = $provisioner;

        $account = $this->factory()->create($this->request(testUser: true, directoryAccount: false));

        self::assertNull($account->directoryRequest);
        self::assertTrue($account->user->isTestUser());
        // The account and the first line of its login ledger, and nothing else - no queue row.
        self::assertSame([$account->user], array_values(array_filter(
            $this->persisted,
            static fn (object $row): bool => !$row instanceof UserLogin,
        )));
        self::assertContainsOnlyInstancesOf(UserLogin::class, array_filter(
            $this->persisted,
            static fn (object $row): bool => $row instanceof UserLogin,
        ));
    }

    // The login is still generated and reserved: the record is still a record, and a namesake
    // created later must not be handed the same one.
    public function testAnAccountOutsideTheDirectoryStillHoldsALogin(): void
    {
        $account = $this->factory()->create($this->request(directoryAccount: false));

        self::assertSame('mdupont', $account->user->getUsername());
    }

    public function testPassesTheLoginsAlreadyHandedOutToTheGenerator(): void
    {
        $loginGenerator = $this->createMock(LoginGenerator::class);
        $loginGenerator->expects(self::once())
            ->method('generate')
            ->with('Marie', 'Dupont', ['mdupont'])
            ->willReturn('mdupont01');
        $this->loginGenerator = $loginGenerator;

        $account = $this->factory()->create($this->request(firstname: 'Marie'), ['mdupont']);

        self::assertSame('mdupont01', $account->user->getUsername());
    }

    // state 0 is what manage_user.php claims lines by; anything else and the row is never picked up.
    public function testTheQueueRowIsLeftForTheDirectoryScriptToClaim(): void
    {
        $account = $this->factory()->create($this->request());

        self::assertInstanceOf(LdapManageUser::class, $account->directoryRequest);
        self::assertSame(0, $account->directoryRequest->getState());
        self::assertNull($account->directoryRequest->getPid());
    }

    private function factory(): StudentAccountFactory
    {
        return new StudentAccountFactory(
            $this->entityManager,
            $this->loginGenerator,
            new LdapManageUserRoleResolver(),
            $this->contactEmailVerifier,
            $this->mailProvisioner,
            new UserLoginHistory($this->createStub(UserLoginRepository::class), $this->entityManager),
        );
    }

    /** @return \Closure(object): void */
    private function recordPersist(): \Closure
    {
        return function (object $entity): void {
            $this->persisted[] = $entity;
        };
    }

    /** @param list<string> $groups */
    private function request(
        string $firstname = 'Martin',
        string $lastname = 'Dupont',
        string $userType = 'student',
        array $groups = [],
        ?string $contactEmail = 'martin@example.org',
        bool $testUser = false,
        bool $directoryAccount = true,
    ): NewAccountRequest {
        return new NewAccountRequest(
            firstname: $firstname,
            lastname: $lastname,
            userType: $userType,
            addedBy: 'sbouby',
            groups: $groups,
            contactEmail: $contactEmail,
            phoneNumber: '0102030405',
            mustChangePassword: true,
            testUser: $testUser,
            directoryAccount: $directoryAccount,
        );
    }
}
