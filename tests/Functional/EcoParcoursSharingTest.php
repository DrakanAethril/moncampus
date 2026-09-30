<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * A parcours shared with a colleague: it is in their list next to their own, they hold every right
 * its creator holds, and only a teacher who runs e-CO - both roles - can be put on the list.
 */
class EcoParcoursSharingTest extends FunctionalTestCase
{
    private User $owner;
    private User $colleague;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->owner = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_ECO'], 'eco.owner');
        $this->colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_ECO'], 'eco.colleague');
    }

    public function testASharedParcoursIsListedAndEditedLikeOnesOwn(): void
    {
        $notShared = $this->createParcours('Parc du Mas');
        $parcours = $this->createParcours();
        $parcours->shareWith($this->colleague);
        $this->entityManager->flush();

        $this->assertScreens($this->colleague, [
            \sprintf('/eco/parcours/%d/configure', $notShared->getId()) => 403,
            \sprintf('/eco/parcours/%d/courses', $notShared->getId()) => 403,
            \sprintf('/eco/parcours/%d/configure', $parcours->getId()) => 200,
            \sprintf('/eco/parcours/%d/courses', $parcours->getId()) => 200,
        ]);

        $list = $this->client->request('GET', '/eco/parcours');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $list->filter('.cm-eco-list__name'));
        self::assertStringContainsString('Bois de la Bastide', $list->filter('.cm-eco-list__name')->text());
        self::assertStringContainsString('Eco.owner', $list->filter('.cm-eco-list__sub')->text());

        // The teacher app reads the same list.
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.static::getContainer()->get(JWTTokenManagerInterface::class)->create($this->colleague));
        $this->client->request('GET', '/api/eco/teacher/parcours/ready');
        $ready = JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
        self::assertSame([$parcours->getId()], array_map(static fn (JsonRequestPayload $row): ?int => $row->int('id'), $ready->objects('parcours')));
    }

    public function testOnlyTeachersWhoRunEcoAreOfferedAndKept(): void
    {
        $parcours = $this->createParcours();
        $teacherOnly = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'teacher.only');
        $ecoOnly = $this->createUser(['ROLE_USER', 'ROLE_ECO'], 'eco.only');

        $this->client->loginUser($this->owner);
        $this->client->request('GET', \sprintf('/eco/parcours/share/candidates?parcours=%d&q=%s', $parcours->getId(), 'o'));
        $offered = array_map(
            static fn (JsonRequestPayload $row): ?int => $row->int('id'),
            JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent())->objects('results'),
        );
        self::assertSame([$this->colleague->getId()], $offered);

        $this->client->request('GET', \sprintf('/eco/parcours/%d/configure', $parcours->getId()));
        $this->client->request('POST', \sprintf('/eco/parcours/%d/share', $parcours->getId()), [
            '_token' => $this->csrfToken('eco_parcours_share'),
            'sharedWith' => [(string) $this->colleague->getId(), (string) $teacherOnly->getId(), (string) $ecoOnly->getId(), (string) $this->owner->getId()],
        ]);
        self::assertResponseRedirects();

        // The client rebooted the kernel: read the parcours back through the container it now runs.
        $saved = static::getContainer()->get(EntityManagerInterface::class)->find(EcoParcours::class, $parcours->getId());
        self::assertInstanceOf(EcoParcours::class, $saved);
        self::assertSame([$this->colleague->getId()], array_map(static fn (User $user): ?int => $user->getId(), $saved->getSharedWith()->toArray()));
    }

    private function createParcours(string $name = 'Bois de la Bastide'): EcoParcours
    {
        $parcours = new EcoParcours($this->owner);
        $parcours->setName($name);
        $parcours->setCreatedBy($this->owner);
        $this->entityManager->persist($parcours);

        foreach ([EcoCheckpointType::Start, EcoCheckpointType::Checkpoint, EcoCheckpointType::Finish] as $position => $type) {
            $checkpoint = new EcoCheckpoint($parcours);
            $checkpoint->setType($type);
            $checkpoint->setPosition($position);
            $checkpoint->setName($type->value);
            $checkpoint->setShortCode(strtoupper(substr(bin2hex(random_bytes(5)), 0, 7)));
            $checkpoint->locate(45.83 + $position / 1000, 1.26, new \DateTimeImmutable());
            $parcours->addCheckpoint($checkpoint);
            $this->entityManager->persist($checkpoint);
        }

        $this->entityManager->flush();

        return $parcours;
    }
}
