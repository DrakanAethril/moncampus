<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MessageThread;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Service\AudienceResolver;
use App\Service\MessageAudienceMerger;
use PHPUnit\Framework\TestCase;

/**
 * Who receives a message when the sender ticks several audiences at once.
 *
 * The composer's live counter and the actual send both go through this, and that is the whole
 * point: the handoff asks for a count "calculé et dédoublonné côté serveur, et affiché de façon
 * identique" next to the recipients and in the footer, so a counter that disagrees with what is
 * sent is worse than no counter at all.
 *
 * What makes them agree is that the counter asks about a probe thread built exactly like the one
 * the send will save - so that, and not the arithmetic, is what these tests pin. The arithmetic
 * itself (union, deduplication, order) belongs to App\Service\AudienceResolver and is tested
 * against the real thing in AudienceResolverTest; asserting it here would only re-test a stub.
 */
class MessageAudienceMergerTest extends TestCase
{
    private int $nextId = 1;

    public function testTheProbeCarriesTheWholeTickedSet(): void
    {
        $probe = $this->capturedProbe(
            [MessageAudienceType::AllTeachers, MessageAudienceType::AllStaff],
            [],
            includeStudents: false,
            includeTeachers: false,
            manualUsers: [],
        );

        self::assertSame([MessageAudienceType::AllTeachers, MessageAudienceType::AllStaff], $probe->getAudienceTypes());
    }

    public function testTheProbeCarriesTheProgramsAndTheirRoleFlags(): void
    {
        // Otherwise the counter would answer for "the whole class" while the send goes to students
        // only, or the reverse.
        $program = (new \ReflectionClass(Program::class))->newInstanceWithoutConstructor();

        $probe = $this->capturedProbe(
            [MessageAudienceType::Program],
            [$program],
            includeStudents: true,
            includeTeachers: false,
            manualUsers: [],
        );

        self::assertSame([$program], $probe->getPrograms()->toArray());
        self::assertTrue($probe->isIncludeStudents());
        self::assertFalse($probe->isIncludeTeachers());
    }

    public function testTheProbeCarriesTheManualPicks(): void
    {
        // Manual users arrive already resolved against the sender's permission matrix - the probe
        // hands them to the resolver as the thread's own named picks rather than re-searching for
        // them.
        $picked = $this->user();

        $probe = $this->capturedProbe(
            [MessageAudienceType::Manual],
            [],
            includeStudents: false,
            includeTeachers: false,
            manualUsers: [$picked],
        );

        self::assertSame([$picked], $probe->getManualRecipients()->toArray());
    }

    /** A sender is never a recipient of their own message, whichever audience reached them. */
    public function testTheSenderIsExcluded(): void
    {
        $sender = $this->user();
        $resolver = $this->createMock(AudienceResolver::class);
        $resolver->expects(self::once())
            ->method('resolveRecipients')
            ->with(self::isInstanceOf(MessageThread::class), self::identicalTo($sender))
            ->willReturn([]);

        (new MessageAudienceMerger($resolver))->merge($sender, [MessageAudienceType::AllStaff], [], false, false, []);
    }

    public function testTheResolvedListIsReturnedAsIs(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        $resolver = $this->createStub(AudienceResolver::class);
        $resolver->method('resolveRecipients')->willReturn([$alice, $bob]);

        self::assertSame(
            [$alice, $bob],
            (new MessageAudienceMerger($resolver))->merge($this->user(), [MessageAudienceType::AllStaff], [], false, false, []),
        );
    }

    public function testNothingTickedReachesNobody(): void
    {
        // An empty set has no branch to fold over, so the resolver answers nobody - the merger
        // does not need a special case for it and must not grow one.
        $resolver = $this->createStub(AudienceResolver::class);
        $resolver->method('resolveRecipients')->willReturnCallback(
            static fn (MessageThread $probe): array => [] === $probe->getAudienceTypes() ? [] : [new \stdClass()],
        );

        self::assertSame([], (new MessageAudienceMerger($resolver))->merge($this->user(), [], [], false, false, []));
    }

    /**
     * @param list<MessageAudienceType> $checkedTypes
     * @param list<Program>             $programs
     * @param list<User>                $manualUsers
     */
    private function capturedProbe(array $checkedTypes, array $programs, bool $includeStudents, bool $includeTeachers, array $manualUsers): MessageThread
    {
        $captured = null;

        $resolver = $this->createStub(AudienceResolver::class);
        $resolver->method('resolveRecipients')->willReturnCallback(
            static function (MessageThread $probe) use (&$captured): array {
                $captured = $probe;

                return [];
            },
        );

        (new MessageAudienceMerger($resolver))->merge($this->user(), $checkedTypes, $programs, $includeStudents, $includeTeachers, $manualUsers);

        self::assertInstanceOf(MessageThread::class, $captured, 'the merger must always ask the resolver');

        return $captured;
    }

    private function user(): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($user, 'id'))->setValue($user, $this->nextId++);

        return $user;
    }
}
