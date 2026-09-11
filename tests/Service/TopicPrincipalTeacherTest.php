<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\LessonSessionRepository;
use App\Service\TopicPrincipalTeacher;
use PHPUnit\Framework\TestCase;

/**
 * One name for a matière that has several titulaires - the Livret alternant's « Formateur » cell,
 * and the author credited to an evaluation born of a travail whose own creator is gone.
 *
 * The point of these tests is that the answer is *stable*: the same matière must never name two
 * different people from one export to the next, which is why the tie-break is alphabetical rather
 * than left to row order.
 */
class TopicPrincipalTeacherTest extends TestCase
{
    public function testTheTitulaireHoldingTheMostCreneauxAnswers(): void
    {
        $zoe = new User('zoe');
        $alice = new User('alice');
        $topic = $this->topic([$alice, $zoe]);

        // Alphabetically last, but three créneaux against one.
        $resolver = $this->resolver([$topic->getId() => [$this->id($zoe) => 3, $this->id($alice) => 1]]);

        self::assertSame($zoe, $resolver->resolve($topic));
    }

    public function testAnEqualNumberOfCreneauxIsBrokenAlphabetically(): void
    {
        $zoe = new User('zoe');
        $alice = new User('alice');
        $topic = $this->topic([$zoe, $alice]);

        $resolver = $this->resolver([$topic->getId() => [$this->id($zoe) => 2, $this->id($alice) => 2]]);

        self::assertSame($alice, $resolver->resolve($topic));
    }

    /** A matière with no créneau yet still has to name somebody: the first titulaire, alphabetically. */
    public function testAMatiereWithNoCreneauNamesItsFirstTitulaire(): void
    {
        $zoe = new User('zoe');
        $alice = new User('alice');
        $topic = $this->topic([$zoe, $alice]);

        self::assertSame($alice, $this->resolver([])->resolve($topic));
    }

    /** A teacher standing in a créneau without being a titulaire is a remplaçant, not the formateur. */
    public function testATeacherWhoIsNotATitulaireIsNeverReturned(): void
    {
        $titulaire = new User('titulaire');
        $standIn = new User('stand-in');
        $topic = $this->topic([$titulaire]);

        $resolver = $this->resolver([$topic->getId() => [$this->id($standIn) => 9, $this->id($titulaire) => 1]]);

        self::assertSame($titulaire, $resolver->resolve($topic));
    }

    public function testAMatiereWithoutTitulaireAnswersNothing(): void
    {
        self::assertNull($this->resolver([])->resolve($this->topic([])));
    }

    /** @param list<User> $teachers */
    private function topic(array $teachers): Topic
    {
        $program = (new \ReflectionClass(Program::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($program, 'id'))->setValue($program, 7);
        (new \ReflectionProperty($program, 'topics'))->setValue($program, new \Doctrine\Common\Collections\ArrayCollection());

        $topic = (new \ReflectionClass(Topic::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($topic, 'id'))->setValue($topic, 42);
        (new \ReflectionProperty($topic, 'teachers'))->setValue($topic, new \Doctrine\Common\Collections\ArrayCollection());
        (new \ReflectionProperty($topic, 'program'))->setValue($topic, $program);

        $nextId = 100;
        foreach ($teachers as $teacher) {
            (new \ReflectionProperty($teacher, 'id'))->setValue($teacher, $nextId++);
            $topic->addTeacher($teacher);
        }

        return $topic;
    }

    private function id(User $user): int
    {
        return (int) $user->getId();
    }

    /** @param array<int, array<int, int>> $counts topic id => (teacher id => créneaux) */
    private function resolver(array $counts): TopicPrincipalTeacher
    {
        $sessions = $this->createStub(LessonSessionRepository::class);
        $sessions->method('countSessionsByTopicAndTeacherForProgram')->willReturn($counts);

        return new TopicPrincipalTeacher($sessions);
    }
}
