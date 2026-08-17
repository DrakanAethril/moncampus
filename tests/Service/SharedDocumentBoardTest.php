<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FileLibraryNode;
use App\Entity\Program;
use App\Entity\SharedDocument;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Enum\SharedDocumentGrouping;
use App\Enum\SharedDocumentOrdering;
use App\Service\SharedDocumentBoard;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the student's list: matière ASC then mise à disposition DESC by default, with two
 * knobs over it.
 *
 * Three things here are decisions rather than mechanics, and each has its own test: the date sorted
 * on is `availableAt()` and not the creation date, a document scheduled for later being unavailable
 * today; the unnamed bucket sorts last rather than where an empty string would put it, first; and
 * grouping by teacher is the same cut on another key, not another screen.
 */
class SharedDocumentBoardTest extends TestCase
{
    private SharedDocumentBoard $board;
    private User $teacher;

    protected function setUp(): void
    {
        $this->board = new SharedDocumentBoard();
        $this->teacher = self::person('mdupont', 'Marie', 'Dupont');
    }

    public function testTheDefaultCutIsBySubjectAlphabeticallyNewestFirstInside(): void
    {
        $groups = $this->board->build([
            $this->share('vieux.pdf', 'Mathématiques', '2026-01-10 08:00'),
            $this->share('algo.pdf', 'Algorithmique', '2026-02-01 08:00'),
            $this->share('neuf.pdf', 'Mathématiques', '2026-03-05 08:00'),
        ], SharedDocumentGrouping::Topic, SharedDocumentOrdering::Date);

        self::assertSame(['Algorithmique', 'Mathématiques'], array_column($groups, 'label'));
        self::assertSame(['neuf.pdf', 'vieux.pdf'], $this->namesOf($groups[1]));
    }

    public function testAlphabeticalReordersInsideTheGroupWithoutMovingTheGroups(): void
    {
        $groups = $this->board->build([
            $this->share('zeta.pdf', 'Mathématiques', '2026-03-05 08:00'),
            $this->share('alpha.pdf', 'Mathématiques', '2026-01-10 08:00'),
        ], SharedDocumentGrouping::Topic, SharedDocumentOrdering::Name);

        self::assertSame(['alpha.pdf', 'zeta.pdf'], $this->namesOf($groups[0]));
    }

    public function testTheDateSortedOnIsWhenItBecameAvailableNotWhenItWasCreated(): void
    {
        // Both rows are created now; only the opening bound tells them apart, and a document opening
        // next month must not sit above one already readable.
        $groups = $this->board->build([
            $this->share('plus-tard.pdf', 'Mathématiques', '2027-09-01 08:00'),
            $this->share('deja-la.pdf', 'Mathématiques', '2026-01-01 08:00'),
        ], SharedDocumentGrouping::Topic, SharedDocumentOrdering::Date);

        self::assertSame(['plus-tard.pdf', 'deja-la.pdf'], $this->namesOf($groups[0]));
    }

    public function testTheSubjectlessBucketSortsLastRatherThanFirst(): void
    {
        $groups = $this->board->build([
            $this->share('orphelin.pdf', null, '2026-03-05 08:00'),
            $this->share('zoologie.pdf', 'Zoologie', '2026-03-05 08:00'),
        ], SharedDocumentGrouping::Topic, SharedDocumentOrdering::Date);

        self::assertSame(['Zoologie', null], array_column($groups, 'label'));
    }

    public function testGroupingByTeacherIsTheSameCutOnAnotherKey(): void
    {
        $other = self::person('amartin', 'Alice', 'Martin');

        $groups = $this->board->build([
            $this->share('a.pdf', 'Mathématiques', '2026-03-05 08:00'),
            $this->share('b.pdf', 'Mathématiques', '2026-03-06 08:00', $other),
        ], SharedDocumentGrouping::Teacher, SharedDocumentOrdering::Date);

        self::assertSame(['Alice Martin', 'Marie Dupont'], array_column($groups, 'label'));
    }

    public function testAFlatListIsOneGroupWithNoHeading(): void
    {
        $groups = $this->board->build([
            $this->share('vieux.pdf', 'Algorithmique', '2026-01-10 08:00'),
            $this->share('neuf.pdf', 'Mathématiques', '2026-03-05 08:00'),
        ], SharedDocumentGrouping::None, SharedDocumentOrdering::Date);

        self::assertCount(1, $groups);
        self::assertNull($groups[0]['label']);
        self::assertSame(['neuf.pdf', 'vieux.pdf'], $this->namesOf($groups[0]));
    }

    public function testNothingSharedIsNoGroupAtAll(): void
    {
        self::assertSame([], $this->board->build([], SharedDocumentGrouping::Topic, SharedDocumentOrdering::Date));
        self::assertSame([], $this->board->build([], SharedDocumentGrouping::None, SharedDocumentOrdering::Date));
    }

    private static function person(string $username, string $firstname, string $lastname): User
    {
        // getDisplayName() is computed from the two LDAP-synced columns, never stored - so a test
        // person is built the way a login builds one.
        $user = new User($username);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);

        return $user;
    }

    private function share(string $fileName, ?string $topicName, string $availableAt, ?User $teacher = null): SharedDocument
    {
        $teacher ??= $this->teacher;
        $node = new FileLibraryNode($teacher, FileLibraryNodeType::File, $fileName);

        $share = new SharedDocument($node, $teacher, $this->createStub(Program::class));
        $share->setVisibleFrom(new \DateTimeImmutable($availableAt));

        if (null !== $topicName) {
            $topic = $this->createStub(Topic::class);
            $topic->method('getName')->willReturn($topicName);
            $share->setTopic($topic);
        }

        return $share;
    }

    /**
     * @param array{label: ?string, shares: list<SharedDocument>} $group
     *
     * @return list<string>
     */
    private function namesOf(array $group): array
    {
        return array_map(
            static fn (SharedDocument $share): string => $share->getLibraryNode()->getName(),
            $group['shares'],
        );
    }
}
