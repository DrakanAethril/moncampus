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
 * The composer's live counter and the actual send both go through this. That is the whole point:
 * the handoff asks for a count "calculé et dédoublonné côté serveur, et affiché de façon identique"
 * next to the recipients and in the footer, so a counter that disagrees with what is sent is worse
 * than no counter at all. A classmate who is both a student of the class and picked by hand must be
 * counted once.
 */
class MessageAudienceMergerTest extends TestCase
{
    private int $nextId = 1;

    public function testEachTickedAudienceContributesItsRecipients(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        $merger = $this->mergerReturning([
            MessageAudienceType::Program->value => [$alice],
            MessageAudienceType::AllStaff->value => [$bob],
        ]);

        $merged = $merger->merge($this->user(), [MessageAudienceType::Program, MessageAudienceType::AllStaff], [], false, false, []);

        self::assertSame([$alice, $bob], $merged);
    }

    public function testSomebodyReachedTwiceIsCountedOnce(): void
    {
        // The case the counter exists for: a student of the class who is also picked by hand.
        $alice = $this->user();

        $merger = $this->mergerReturning([
            MessageAudienceType::Program->value => [$alice],
            MessageAudienceType::AllStaff->value => [$alice],
        ]);

        $merged = $merger->merge($this->user(), [MessageAudienceType::Program, MessageAudienceType::AllStaff], [], false, false, []);

        self::assertSame([$alice], $merged);
    }

    public function testManualRecipientsAreMergedWithoutBeingResolvedAgain(): void
    {
        // Manual users arrive already resolved against the sender's permission matrix, so the
        // merger must not probe that type - a MessageThread has no "Manual plus something else"
        // audience it could ask about.
        $fromProgram = $this->user();
        $picked = $this->user();

        $resolver = $this->createMock(AudienceResolver::class);
        $resolver->expects(self::once())
            ->method('resolveRecipients')
            ->willReturn([$fromProgram]);

        $merged = (new MessageAudienceMerger($resolver))->merge(
            $this->user(),
            [MessageAudienceType::Program, MessageAudienceType::Manual],
            [],
            false,
            false,
            [$picked],
        );

        self::assertSame([$fromProgram, $picked], $merged);
    }

    public function testAManualPickAlreadyInAnAudienceIsNotDuplicated(): void
    {
        $alice = $this->user();

        $resolver = $this->createStub(AudienceResolver::class);
        $resolver->method('resolveRecipients')->willReturn([$alice]);

        $merged = (new MessageAudienceMerger($resolver))->merge(
            $this->user(),
            [MessageAudienceType::Program],
            [],
            false,
            false,
            [$alice],
        );

        self::assertSame([$alice], $merged);
    }

    public function testTheProgramProbeCarriesTheProgramsAndTheirRoleFlags(): void
    {
        // Otherwise the counter would answer for "the whole class" while the send goes to students
        // only, or the reverse.
        $program = (new \ReflectionClass(Program::class))->newInstanceWithoutConstructor();

        $resolver = $this->createMock(AudienceResolver::class);
        $resolver->expects(self::once())
            ->method('resolveRecipients')
            ->with(self::callback(static function (MessageThread $probe) use ($program): bool {
                return MessageAudienceType::Program === $probe->getAudienceType()
                    && [$program] === $probe->getPrograms()->toArray()
                    && $probe->isIncludeStudents()
                    && !$probe->isIncludeTeachers();
            }))
            ->willReturn([]);

        (new MessageAudienceMerger($resolver))->merge(
            $this->user(),
            [MessageAudienceType::Program],
            [$program],
            includeStudents: true,
            includeTeachers: false,
            manualUsers: [],
        );
    }

    public function testNothingTickedReachesNobody(): void
    {
        $resolver = $this->createMock(AudienceResolver::class);
        $resolver->expects(self::never())->method('resolveRecipients');

        self::assertSame([], (new MessageAudienceMerger($resolver))->merge($this->user(), [], [], false, false, []));
    }

    public function testOnlyManualPicksNeedNoResolution(): void
    {
        $picked = $this->user();

        $resolver = $this->createMock(AudienceResolver::class);
        $resolver->expects(self::never())->method('resolveRecipients');

        self::assertSame([$picked], (new MessageAudienceMerger($resolver))->merge(
            $this->user(),
            [MessageAudienceType::Manual],
            [],
            false,
            false,
            [$picked],
        ));
    }

    /** @param array<string, list<User>> $byAudienceType */
    private function mergerReturning(array $byAudienceType): MessageAudienceMerger
    {
        $resolver = $this->createStub(AudienceResolver::class);
        $resolver->method('resolveRecipients')->willReturnCallback(
            static fn (MessageThread $probe): array => $byAudienceType[$probe->getAudienceType()->value] ?? [],
        );

        return new MessageAudienceMerger($resolver);
    }

    private function user(): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($user, 'id'))->setValue($user, $this->nextId++);

        return $user;
    }
}
