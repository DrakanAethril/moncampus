<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Enum\EcoCourseMode;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * « Balises spécifiques »: a course run on flags 1 and 3 of a five-flag parcours. For that race
 * flag 2 does not exist - the runner is not shown it, its code is unknown, and nobody is expected
 * to find it - and « dans l'ordre » expects 1 before 3 where « au choix » does not.
 *
 * The order of the race is the teacher's, never presumed to be the parcours': a race may send the
 * runners to 3 before 1. The runner app predates the mode: it is told imposed_order or free_order,
 * and in order each flag's rank in the race as its `position`, which is what it sorts by to find
 * the next one.
 */
class EcoSpecificCheckpointsRaceTest extends FunctionalTestCase
{
    private User $teacher;
    private EntityManagerInterface $entityManager;

    /** @var array<string, EcoCheckpoint> D, 1, 2, 3, A */
    private array $checkpoints = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.teacher');
    }

    public function testTheRunnerIsShownOnlyTheChosenFlagsAndInOrderIsImposedOrder(): void
    {
        $course = $this->createRunningCourse(ordered: true);

        $join = $this->request('POST', '/api/eco/runner/join', ['pseudo' => 'lilou', 'code' => $course->getCode()]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('imposed_order', $join->string('mode'));
        self::assertSame('balises spécifiques · dans l’ordre', $join->string('modeLabel'));
        $names = array_map(static fn (JsonRequestPayload $checkpoint): string => $checkpoint->string('name'), $join->objects('checkpoints'));
        self::assertSame(['D', '1', '3', 'A'], $names);
    }

    public function testAFlagLeftOutIsUnknownAndInOrderMeansTheChosenOrder(): void
    {
        $course = $this->createRunningCourse(ordered: true);
        $token = $this->join($course);

        self::assertSame('success', $this->scan($token, 'D')->string('result'));

        $leftOut = $this->scan($token, '2');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('checkpointNotFound', $leftOut->string('error'));

        self::assertSame('out_of_order', $this->scan($token, '3')->string('result'));
        // Flag 2 is not expected in between: after 1 comes 3.
        self::assertSame('success', $this->scan($token, '1')->string('result'));
        self::assertSame('success', $this->scan($token, '3')->string('result'));
        self::assertSame('success', $this->scan($token, 'A')->string('result'));

        $summary = $this->request('GET', '/api/eco/runner/summary?token='.$token);
        self::assertSame(2, $summary->int('checkpointsValidated'));
        self::assertSame(2, $summary->int('checkpointsTotal'));
        self::assertSame('imposed_order', $summary->string('mode'));
    }

    public function testInAnyOrderTheChosenFlagsAreTakenAsTheRunnerLikes(): void
    {
        $course = $this->createRunningCourse(ordered: false);
        $token = $this->join($course);

        self::assertSame('free_order', $this->request('GET', '/api/eco/runner/state?token='.$token)->string('mode'));
        self::assertSame('success', $this->scan($token, 'D')->string('result'));
        self::assertSame('success', $this->scan($token, '3')->string('result'));
        self::assertSame('success', $this->scan($token, '1')->string('result'));
        self::assertSame('success', $this->scan($token, 'A')->string('result'));
    }

    public function testInOrderTheRaceFollowsTheTeachersRankNotTheParcours(): void
    {
        $course = $this->createRunningCourse(ordered: true, chosen: ['3', '1']);

        $join = $this->request('POST', '/api/eco/runner/join', ['pseudo' => 'lilou', 'code' => $course->getCode()]);
        $checkpoints = $join->objects('checkpoints');
        self::assertSame(['D', '3', '1', 'A'], array_map(static fn (JsonRequestPayload $checkpoint): string => $checkpoint->string('name'), $checkpoints));
        // What the app sorts by: Départ 0, the flags in the race's order, Arrivée last.
        self::assertSame([0, 1, 2, 3], array_map(static fn (JsonRequestPayload $checkpoint): ?int => $checkpoint->int('position'), $checkpoints));

        $token = $join->string('token');
        self::assertSame('success', $this->scan($token, 'D')->string('result'));
        $next = array_values(array_filter(
            $this->request('GET', '/api/eco/runner/state?token='.$token)->objects('checkpoints'),
            static fn (JsonRequestPayload $checkpoint): bool => $checkpoint->bool('isNext', false),
        ));
        self::assertSame(['3'], array_map(static fn (JsonRequestPayload $checkpoint): string => $checkpoint->string('name'), $next));

        self::assertSame('out_of_order', $this->scan($token, '1')->string('result'));
        self::assertSame('success', $this->scan($token, '3')->string('result'));
        self::assertSame('success', $this->scan($token, '1')->string('result'));
        self::assertSame('success', $this->scan($token, 'A')->string('result'));
    }

    public function testInAnyOrderTheAppKeepsTheParcoursNumbers(): void
    {
        $course = $this->createRunningCourse(ordered: false, chosen: ['3', '1']);

        $join = $this->request('POST', '/api/eco/runner/join', ['pseudo' => 'lilou', 'code' => $course->getCode()]);

        self::assertSame([0, 3, 1, 4], array_map(static fn (JsonRequestPayload $checkpoint): ?int => $checkpoint->int('position'), $join->objects('checkpoints')));
    }

    public function testTheEditScreenSavesTheOrderOfTheList(): void
    {
        $course = $this->createRunningCourse(ordered: true, chosen: ['1'], started: false);
        $this->client->loginUser($this->teacher);

        $crawler = $this->client->request('GET', \sprintf('/eco/courses/%d/edit', $course->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="eco_course"]')->form();
        $this->tickFlags($form, ['1', '2', '3']);
        // What eco_checkpoint_order_controller writes once 3 is dragged to the top.
        $form['eco_course[specificCheckpointOrder]']->setValue(implode(',', [$this->flagId('3'), $this->flagId('1'), $this->flagId('2')]));
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $saved = $this->entityManager->find(EcoCourse::class, $course->getId());
        self::assertNotNull($saved);
        self::assertSame(['3', '1', '2'], array_map(static fn (EcoCheckpoint $checkpoint): string => (string) $checkpoint->getName(), $saved->getSpecificCheckpoints()));
        self::assertSame(['D', '3', '1', '2', 'A'], array_map(static fn (EcoCheckpoint $checkpoint): string => (string) $checkpoint->getName(), $saved->getRaceCheckpoints()));

        // Unticking keeps the others in their order, without a gap in the ranks.
        $crawler = $this->client->request('GET', \sprintf('/eco/courses/%d/edit', $course->getId()));
        $rows = $crawler->filter('.cm-eco-order__row')->each(static fn ($row): string => trim($row->text()));
        self::assertSame(['3', '1', '2'], $rows, 'The ticked flags come first, in the race order, then the others.');
        $form = $crawler->filter('form[name="eco_course"]')->form();
        $this->tickFlags($form, ['3', '2']);
        $this->client->submit($form);

        $this->entityManager->clear();
        $saved = $this->entityManager->find(EcoCourse::class, $course->getId());
        self::assertNotNull($saved);
        self::assertSame(['3', '2'], array_map(static fn (EcoCheckpoint $checkpoint): string => (string) $checkpoint->getName(), $saved->getSpecificCheckpoints()));
        $ranks = array_map(static fn (EcoCheckpoint $checkpoint): ?int => $saved->getSpecificRank($checkpoint), $saved->getSpecificCheckpoints());
        self::assertSame([1, 2], $ranks);
    }

    /**
     * Ticks the flags named, unticks the others - by value: the boxes are drawn in the race's order.
     *
     * @param list<string> $labels
     */
    private function tickFlags(Form $form, array $labels): void
    {
        $ids = array_map(fn (string $label): string => (string) $this->flagId($label), $labels);
        $fields = $form['eco_course[specificCheckpoints]'];
        self::assertIsArray($fields);
        foreach ($fields as $field) {
            self::assertInstanceOf(ChoiceFormField::class, $field);
            if (\in_array($field->availableOptionValues()[0] ?? '', $ids, true)) {
                $field->tick();
            } else {
                $field->untick();
            }
        }
    }

    public function testTheTeacherAppsOrderIsTheRacesAndComesBackAsSent(): void
    {
        $parcours = $this->createRunningCourse(ordered: true)->getParcours();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->teacher));

        $ids = [$this->flagId('3'), $this->flagId('1')];
        $course = $this->request('POST', \sprintf('/api/eco/teacher/parcours/%d/courses', $parcours->getId()), [
            'name' => 'Balises dans le désordre',
            'mode' => 'specific_checkpoints',
            'specificCheckpointIds' => $ids,
            'specificOrdered' => true,
        ])->object('course');

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame($ids, $course->ids('specificCheckpointIds'));
    }

    private function flagId(string $label): int
    {
        return (int) $this->checkpoints[$label]->getId();
    }

    private function join(EcoCourse $course): string
    {
        return $this->request('POST', '/api/eco/runner/join', ['pseudo' => 'lilou', 'code' => $course->getCode()])->string('token');
    }

    private function scan(string $token, string $label): JsonRequestPayload
    {
        $checkpoint = $this->checkpoints[$label];

        return $this->request('POST', '/api/eco/runner/scan', [
            'token' => $token,
            'code' => $checkpoint->getShortCode(),
            'latitude' => $checkpoint->getLatitude(),
            'longitude' => $checkpoint->getLongitude(),
        ]);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): JsonRequestPayload
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, \JSON_THROW_ON_ERROR) : null);

        return JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
    }

    /**
     * D, 1, 2, 3, A, all located; a course on the flags chosen, ranked in that order.
     *
     * @param list<string> $chosen
     */
    private function createRunningCourse(bool $ordered, array $chosen = ['1', '3'], bool $started = true): EcoCourse
    {
        $parcours = new EcoParcours($this->teacher);
        $parcours->setName('Bois de la Bastide');
        $parcours->setCreatedBy($this->teacher);
        $this->entityManager->persist($parcours);

        $layout = ['D' => EcoCheckpointType::Start, '1' => EcoCheckpointType::Checkpoint, '2' => EcoCheckpointType::Checkpoint, '3' => EcoCheckpointType::Checkpoint, 'A' => EcoCheckpointType::Finish];
        $position = 0;
        foreach ($layout as $label => $type) {
            $checkpoint = new EcoCheckpoint($parcours);
            $checkpoint->setType($type);
            $checkpoint->setPosition($position);
            $checkpoint->setName((string) $label);
            $checkpoint->setShortCode(strtoupper(substr(bin2hex(random_bytes(5)), 0, 7)));
            $checkpoint->locate(45.83 + $position / 1000, 1.26, new \DateTimeImmutable());
            $parcours->addCheckpoint($checkpoint);
            $this->entityManager->persist($checkpoint);
            $this->checkpoints[(string) $label] = $checkpoint;
            ++$position;
        }

        $course = new EcoCourse($parcours, $this->teacher);
        $course->setName('2NDE B — mercredi');
        $course->setCode(strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        $course->setMode(EcoCourseMode::SpecificCheckpoints);
        $course->setSpecificOrdered($ordered);
        foreach ($chosen as $label) {
            $course->addSpecificCheckpoint($this->checkpoints[$label]);
        }
        if ($started) {
            $course->start(new \DateTimeImmutable());
        }
        $this->entityManager->persist($course);
        $this->entityManager->flush();

        return $course;
    }
}
