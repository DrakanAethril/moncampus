<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SeanceInstance;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Service\ProgressionPlacementService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Valider le placement » after séances have moved to créneaux their neighbours used to hold.
 *
 * SeanceInstance::$lessonSession is a UNIQUE one-to-one, and validate() hands créneaux over from
 * one instance to another inside a single flush. Doctrine issues the UPDATEs in its identity map's
 * order, not in the order the code made the changes, so « release the old occupant, then take the
 * créneau » reached MySQL the other way round and died on UNIQ_AE8B31A66C36A50E - reported from
 * production on POST /progression/1/sequence/26/validate. Only a real database can show it: the
 * unit suite never flushes.
 */
class ProgressionValidateSeanceLinkTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $teacher;
    private Program $program;
    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'progression.teacher');
        $this->program = $this->createProgram([], [$this->teacher]);

        $group = new TopicGroup('Bloc 1', $this->program);
        $group->setCreatedBy($this->teacher);
        $this->topic = new Topic('Cybersécurité', $this->program, $group);
        $this->topic->setCreatedBy($this->teacher);
        $this->entityManager->persist($group);
        $this->entityManager->persist($this->topic);
        $this->entityManager->flush();
    }

    // The production case: séance A moved onto the créneau séance B was linked to, B moved further
    // on. Both links change hands in one validation.
    public function testASeanceCanTakeTheSlotItsNeighbourIsLeaving(): void
    {
        [$first, $second, $third] = [$this->slot('+1 day'), $this->slot('+8 days'), $this->slot('+15 days')];

        $sequence = $this->sequence();
        $a = $this->seance($sequence, 'Séance A', 0, $first);
        $b = $this->seance($sequence, 'Séance B', 1, $second);
        $this->entityManager->flush();

        $placement = static::getContainer()->get(ProgressionPlacementService::class);
        $placement->associate($a, [$second], 'duplicate', null);
        $placement->associate($b, [$third], 'duplicate', null);
        $this->entityManager->flush();

        $this->validate($sequence);

        self::assertResponseRedirects();
        self::assertSame($second->getId(), $this->linkedSlotId($a));
        self::assertSame($third->getId(), $this->linkedSlotId($b));
    }

    // Two séances swapping créneaux: neither UPDATE can run first while the other still holds its
    // target.
    public function testTwoSeancesCanSwapTheirSlots(): void
    {
        [$first, $second] = [$this->slot('+1 day'), $this->slot('+8 days')];

        $sequence = $this->sequence();
        $a = $this->seance($sequence, 'Séance A', 0, $first);
        $b = $this->seance($sequence, 'Séance B', 1, $second);
        $this->entityManager->flush();

        $placement = static::getContainer()->get(ProgressionPlacementService::class);
        $placement->associate($a, [$second], 'duplicate', null);
        $placement->associate($b, [$first], 'duplicate', null);
        $this->entityManager->flush();

        $this->validate($sequence);

        self::assertResponseRedirects();
        self::assertSame($second->getId(), $this->linkedSlotId($a));
        self::assertSame($first->getId(), $this->linkedSlotId($b));
    }

    // Picked by hand, two séances of one séquence can sit on the same créneau (the 2b picker labels
    // a busy créneau, it does not refuse it). Only one instance can be linked to it; the other
    // must be left unlinked rather than blow the unique index.
    public function testTwoSeancesOnOneSlotLinkOnlyOneInstance(): void
    {
        $slot = $this->slot('+1 day');

        $sequence = $this->sequence();
        $a = $this->seance($sequence, 'Séance A', 0, null);
        $b = $this->seance($sequence, 'Séance B', 1, null);
        $this->entityManager->flush();

        $placement = static::getContainer()->get(ProgressionPlacementService::class);
        $placement->associate($a, [$slot], 'duplicate', null);
        $placement->associate($b, [$slot], 'duplicate', null);
        $this->entityManager->flush();

        $this->validate($sequence);

        self::assertResponseRedirects();
        $linked = array_filter([$this->linkedSlotId($a), $this->linkedSlotId($b)]);
        self::assertSame([$slot->getId()], array_values($linked));
    }

    private function validate(ProgressionSequence $sequence): void
    {
        $progressionId = $sequence->getProgression()?->getId();
        $sequenceId = $sequence->getId();

        // The request must load everything afresh, in the order the application does - which is
        // what decides the order Doctrine flushes in.
        $this->entityManager->clear();

        $this->client->loginUser($this->entityManager->find(User::class, $this->teacher->getId()) ?? self::fail('teacher vanished'));
        $this->client->request('GET', '/progression/'.$progressionId);
        $this->client->request('POST', '/progression/'.$progressionId.'/sequence/'.$sequenceId.'/validate', [
            '_token' => $this->csrfToken('progression_placement_validate'),
        ]);
    }

    private function linkedSlotId(ProgressionSeance $seance): ?int
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT lesson_session_id FROM seance_instance WHERE id = ?',
            [$seance->getSeanceInstance()?->getId()],
        );

        return is_numeric($id) ? (int) $id : null;
    }

    private function slot(string $offset): LessonSession
    {
        $day = new \DateTimeImmutable('today '.$offset);

        $session = new LessonSession($this->program);
        $session->setDay($day);
        $session->setStartHour($day->setTime(8, 0));
        $session->setEndHour($day->setTime(10, 0));
        $session->setLength('2.00');
        $session->setTopic($this->topic);
        $session->setTeacher($this->teacher);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function sequence(): ProgressionSequence
    {
        $progression = new Progression($this->topic, $this->teacher);
        $instance = new SequenceInstance($this->program, $this->teacher);
        $instance->setTitre('Séquence 1');

        $sequence = new ProgressionSequence($progression, $instance);
        $sequence->setPosition(0);

        $this->entityManager->persist($progression);
        $this->entityManager->persist($instance);
        $this->entityManager->persist($sequence);

        return $sequence;
    }

    private function seance(ProgressionSequence $sequence, string $title, int $position, ?LessonSession $linkedTo): ProgressionSeance
    {
        $instance = new SeanceInstance($this->program, $this->teacher);
        $instance->setTitre($title);
        $instance->setOrdre($position);
        $instance->setLessonSession($linkedTo);

        $seance = new ProgressionSeance($sequence, $title);
        $seance->setPlannedMinutes(120);
        $seance->setPosition($position);
        $seance->setSeanceInstance($instance);

        $this->entityManager->persist($instance);
        $this->entityManager->persist($seance);

        return $seance;
    }
}
