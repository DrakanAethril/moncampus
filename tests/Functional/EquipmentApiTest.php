<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentType;
use App\Entity\User;
use App\Service\Equipment\EquipmentCode;
use App\Service\Equipment\EquipmentLedger;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * /api/equipment - recording an inventory movement from the phone.
 *
 * The routes carry the web's two locks (the feature, the four keeper roles) and go through the same
 * ledger, so what is pinned here is the door and the shapes: a code read back, a gesture recorded,
 * a refusal answered as a 422 with the same French sentence the web flashes.
 *
 * Authentication is a real LexikJWT token: /api is a stateless firewall.
 */
class EquipmentApiTest extends FunctionalTestCase
{
    private EquipmentLedger $ledger;
    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = static::getContainer()->get(EquipmentLedger::class);
        $this->keeper = $this->createUser(['ROLE_USER', 'ROLE_SUPPORT-TECH'], 'api.equipment.support');
    }

    public function testOnlyTheKeepersReachIt(): void
    {
        $this->authorize($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'api.equipment.teacher'));
        $this->client->request('GET', '/api/equipment/types');
        self::assertResponseStatusCodeSame(403);

        $this->authorize($this->keeper);
        $this->client->request('GET', '/api/equipment/types');
        self::assertResponseIsSuccessful();
    }

    /** Typed from the label, the code finds the piece and says what may be done with it. */
    public function testALabelCodeFindsThePieceAndItsGestures(): void
    {
        [$item] = $this->ledger->createType((new EquipmentType())->setName('Souris API')->setUnitTracked(true), 1, $this->keeper);

        $this->authorize($this->keeper);
        $this->client->request('GET', '/api/equipment/lookup', ['code' => strtolower($item->getCode())]);
        self::assertSame($item->getCode(), $this->field('items', 0, 'code'));
        self::assertSame(['deploy', 'missing', 'out_of_order'], $this->field('items', 0, 'actions'));
        self::assertFalse($this->field('wrongCheckDigit'));

        $wrong = (EquipmentCode::checkDigit($item->getCodeNumber()) + 1) % 10;
        $this->client->request('GET', '/api/equipment/lookup', ['code' => \sprintf('CA-%04d-%d', $item->getCodeNumber(), $wrong)]);
        self::assertSame([], $this->field('items'), 'a wrong digit is never a match');
        self::assertSame($item->getCode(), $this->field('suggestions', 0, 'code'));
        self::assertTrue($this->field('wrongCheckDigit'));
    }

    public function testAGestureOnAPieceGoesThroughTheLedger(): void
    {
        [$item] = $this->ledger->createType((new EquipmentType())->setName('Casques API')->setUnitTracked(true), 1, $this->keeper);
        $this->authorize($this->keeper);

        $this->post('/api/equipment/items/'.$item->getId().'/movements', ['action' => 'deploy']);
        self::assertResponseIsSuccessful();
        self::assertSame('in_use', $this->field('item', 'status'));

        // An incident needs a cause: refused with the web's own sentence.
        $this->post('/api/equipment/items/'.$item->getId().'/movements', ['action' => 'out_of_order']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Choisissez une cause.', $this->field('error'));

        $this->post('/api/equipment/items/'.$item->getId().'/movements', ['action' => 'out_of_order', 'cause' => 'breakdown']);
        self::assertResponseIsSuccessful();
        self::assertSame(['repaired'], $this->field('item', 'actions'));

        $type = static::getContainer()->get('doctrine')->getManager()->find(EquipmentType::class, $item->getType()->getId());
        self::assertInstanceOf(EquipmentType::class, $type);
        self::assertSame([0, 0], [$type->getAvailableCount(), $type->getInUseCount()]);
    }

    public function testAQuantityIsMovedAndAPieceDeliveryAnswersItsCodes(): void
    {
        $cables = (new EquipmentType())->setName('Câbles API');
        $this->ledger->createType($cables, 10, $this->keeper);
        $webcams = (new EquipmentType())->setName('Webcams API')->setUnitTracked(true);
        $this->ledger->createType($webcams, 0, $this->keeper);
        $this->authorize($this->keeper);

        $this->post('/api/equipment/types/'.$cables->getId().'/movements', ['action' => 'deploy', 'quantity' => 4]);
        self::assertSame([6, 4], [$this->field('type', 'available'), $this->field('type', 'inUse')]);

        $this->post('/api/equipment/types/'.$cables->getId().'/movements', ['action' => 'deploy', 'quantity' => 40]);
        self::assertResponseStatusCodeSame(422);

        $this->post('/api/equipment/types/'.$webcams->getId().'/movements', ['action' => 'intake', 'quantity' => 2]);
        self::assertResponseIsSuccessful();
        self::assertCount(2, (array) $this->field('createdCodes'));

        // A piece type moves piece by piece, through its code.
        $this->post('/api/equipment/types/'.$webcams->getId().'/movements', ['action' => 'deploy', 'quantity' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(2, static::getContainer()->get('doctrine')->getRepository(EquipmentItem::class)->findBy(['type' => $webcams->getId()]));
    }

    private function authorize(User $user): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): void
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** One value of the last JSON answer, by its path - `field('items', 0, 'code')`. */
    private function field(string|int ...$path): mixed
    {
        $value = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
