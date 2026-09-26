<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Attribute\RequiresFeature;
use App\Entity\EquipmentItem;
use App\Entity\EquipmentType;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\EquipmentIncidentCause;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use App\Enum\Feature;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentTypeRepository;
use App\Repository\RoomRepository;
use App\Service\Equipment\EquipmentCode;
use App\Service\Equipment\EquipmentCodeCandidate;
use App\Service\Equipment\EquipmentLedger;
use App\Service\Equipment\EquipmentStockException;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestion > Matériel on the phone: find a piece by the code on its label, or pick a type, and record
 * what just happened to it - somebody is holding the mouse, not sitting at a desk.
 *
 * What it offers is the everyday half of the web screens: « Utilisé » / « Disponible », « Disparu »
 * / « Hors d'usage » with a cause, « Retrouvé » / « Réparé », and a delivery. The rest - orders, the
 * annual report, the count, the settings - stays on the web.
 *
 * **Not one rule is re-implemented here.** Every gesture goes through App\Service\Equipment\
 * EquipmentLedger, exactly as on the web, so the counters, the statuses and the refusals are the
 * same; a refusal comes back as a 422 carrying the same French sentence the web flashes.
 *
 * Same two locks as the web: the feature, and the four roles that keep the inventory.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_SUPPORT-TECH")'))]
#[RequiresFeature(Feature::Equipment)]
class EquipmentController extends AbstractController
{
    /** The gestures a single piece accepts from the phone. Disposal stays on the web. */
    private const array ITEM_ACTIONS = ['deploy', 'return', 'missing', 'out_of_order', 'found', 'repaired'];

    /** The gestures a type accepts: a delivery, and for a quantity type the same as a piece. */
    private const array TYPE_ACTIONS = ['intake', 'deploy', 'return', 'missing', 'out_of_order', 'found', 'repaired'];

    public function __construct(
        private readonly EquipmentLedger $ledger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The label code, however it was typed. Answers the pieces it can mean - usually one - and says
     * when the check digit is wrong: the piece it nearly names comes back as `suggestions`, never as
     * a match, because a wrong digit means the label or the typing is wrong.
     */
    #[Route(path: '/api/equipment/lookup', name: 'api_equipment_lookup', methods: ['GET'])]
    public function lookup(Request $request, EquipmentItemRepository $items): JsonResponse
    {
        $candidates = EquipmentCode::candidates($request->query->getString('code')) ?? [];
        $found = $items->findByCodeNumbers(array_map(static fn (EquipmentCodeCandidate $candidate): int => $candidate->number, $candidates));

        $matches = [];
        $suggestions = [];
        foreach ($candidates as $candidate) {
            $item = $found[$candidate->number] ?? null;
            if (!$item instanceof EquipmentItem) {
                continue;
            }

            if ($candidate->isValid()) {
                $matches[$item->getCodeNumber()] = $this->item($item);
            } else {
                $suggestions[$item->getCodeNumber()] = $this->item($item);
            }
        }

        return $this->json([
            'items' => array_values($matches),
            'suggestions' => array_values($suggestions),
            'wrongCheckDigit' => [] !== array_filter($candidates, static fn (EquipmentCodeCandidate $candidate): bool => !$candidate->isValid()),
        ]);
    }

    /** Every type with its counters, by category then name - the list a quantity is picked from. */
    #[Route(path: '/api/equipment/types', name: 'api_equipment_types', methods: ['GET'])]
    public function types(Request $request, EquipmentTypeRepository $types): JsonResponse
    {
        return $this->json(['types' => array_map(
            fn (EquipmentType $type): array => $this->type($type),
            $types->findForStock(null, trim($request->query->getString('q'))),
        )]);
    }

    /** The rooms a « Utilisé » or an incident may name - optional, always. */
    #[Route(path: '/api/equipment/rooms', name: 'api_equipment_rooms', methods: ['GET'])]
    public function rooms(RoomRepository $rooms): JsonResponse
    {
        return $this->json(['rooms' => array_map(
            static fn (Room $room): array => ['id' => (int) $room->getId(), 'name' => $room->getName()],
            $rooms->findAllActiveOrderedByName(),
        )]);
    }

    /**
     * One gesture on one piece. Body: `action` (deploy, return, missing, out_of_order, found,
     * repaired), and for an incident `cause` and optionally `date` (Y-m-d); `roomId` and `note`
     * optional. Answers the piece as it now stands.
     */
    #[Route(path: '/api/equipment/items/{id}/movements', name: 'api_equipment_item_movement', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function itemMovement(int $id, Request $request, EquipmentItemRepository $items, RoomRepository $rooms): JsonResponse
    {
        $item = $items->find($id) ?? throw $this->createNotFoundException();
        $payload = JsonRequestPayload::fromRequest($request);
        $action = $payload->string('action');

        if (!\in_array($action, self::ITEM_ACTIONS, true)) {
            return $this->refusal('equipmentApiUnknownActionMessage');
        }

        $room = $this->room($payload, $rooms);
        $note = $this->note($payload);

        try {
            switch ($action) {
                case 'deploy':
                    $this->ledger->deployItem($item, $room, $this->currentUser());
                    break;
                case 'return':
                    $this->ledger->returnItem($item, $this->currentUser());
                    break;
                case 'found':
                case 'repaired':
                    $this->ledger->resolveItem($item, EquipmentMovementKind::from($action), $note, $this->currentUser());
                    break;
                default:
                    $this->ledger->declareItemIncident(
                        $item,
                        EquipmentMovementKind::from($action),
                        $this->cause($payload) ?? throw new EquipmentStockException('equipmentApiCauseRequiredMessage'),
                        $room,
                        $this->occurredAt($payload),
                        $note,
                        $this->currentUser(),
                    );
            }
        } catch (EquipmentStockException $exception) {
            return $this->refusal($exception->getMessage());
        }

        return $this->json(['item' => $this->item($item)]);
    }

    /**
     * One gesture on a type. Body: `action`, `quantity`; for a quantity type everything a piece
     * accepts (an incident also takes `origin`: available or in_use, default in_use); for any type
     * `intake` - a delivery, which for a unit-tracked type creates the pieces and answers their
     * codes, to type on the Dymo.
     */
    #[Route(path: '/api/equipment/types/{id}/movements', name: 'api_equipment_type_movement', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function typeMovement(int $id, Request $request, EquipmentTypeRepository $types, RoomRepository $rooms): JsonResponse
    {
        $type = $types->find($id) ?? throw $this->createNotFoundException();
        $payload = JsonRequestPayload::fromRequest($request);
        $action = $payload->string('action');
        $quantity = $payload->int('quantity') ?? 0;

        if (!\in_array($action, self::TYPE_ACTIONS, true) || ($type->isUnitTracked() && 'intake' !== $action)) {
            return $this->refusal('equipmentApiUnknownActionMessage');
        }

        $room = $this->room($payload, $rooms);
        $note = $this->note($payload);
        $created = [];

        try {
            switch ($action) {
                case 'intake':
                    $created = $this->ledger->addStock($type, $quantity, $this->currentUser(), $note);
                    break;
                case 'deploy':
                    $this->ledger->deployQuantity($type, $quantity, $room, $this->currentUser());
                    break;
                case 'return':
                    $this->ledger->returnQuantity($type, $quantity, $this->currentUser());
                    break;
                case 'found':
                case 'repaired':
                    $this->ledger->resolveQuantity($type, EquipmentMovementKind::from($action), $quantity, $note, $this->currentUser());
                    break;
                default:
                    $this->ledger->declareQuantityIncident(
                        $type,
                        EquipmentMovementKind::from($action),
                        $quantity,
                        'available' === $payload->string('origin') ? EquipmentItemStatus::Available : EquipmentItemStatus::InUse,
                        $this->cause($payload) ?? throw new EquipmentStockException('equipmentApiCauseRequiredMessage'),
                        $room,
                        $this->occurredAt($payload),
                        $note,
                        $this->currentUser(),
                    );
            }
        } catch (EquipmentStockException $exception) {
            return $this->refusal($exception->getMessage());
        }

        return $this->json([
            'type' => $this->type($type),
            'createdCodes' => array_map(static fn (EquipmentItem $item): string => $item->getCode(), $created),
        ]);
    }

    /**
     * @return array{id: int, code: string, status: string, statusLabel: string, room: string|null, typeId: int, typeName: string, actions: list<string>}
     */
    private function item(EquipmentItem $item): array
    {
        $status = $item->getStatus();

        return [
            'id' => (int) $item->getId(),
            'code' => $item->getCode(),
            'status' => $status->value,
            'statusLabel' => $this->translator->trans($status->labelKey()),
            'room' => $item->getRoom()?->getName(),
            'typeId' => (int) $item->getType()->getId(),
            'typeName' => $item->getType()->getName(),
            // What the phone may offer, decided here so the app never guesses a status rule.
            'actions' => match ($status) {
                EquipmentItemStatus::Available => ['deploy', 'missing', 'out_of_order'],
                EquipmentItemStatus::InUse => ['return', 'missing', 'out_of_order'],
                EquipmentItemStatus::Missing => ['found'],
                EquipmentItemStatus::OutOfOrder => ['repaired'],
                EquipmentItemStatus::Disposed => [],
            },
        ];
    }

    /**
     * @return array{id: int, name: string, category: string|null, unitTracked: bool, available: int, inUse: int, onOrder: int, level: string}
     */
    private function type(EquipmentType $type): array
    {
        return [
            'id' => (int) $type->getId(),
            'name' => $type->getName(),
            'category' => $type->getCategory()?->getName(),
            'unitTracked' => $type->isUnitTracked(),
            'available' => $type->getAvailableCount(),
            'inUse' => $type->getInUseCount(),
            'onOrder' => $type->getOnOrderCount(),
            'level' => $type->stockLevel(),
        ];
    }

    private function room(JsonRequestPayload $payload, RoomRepository $rooms): ?Room
    {
        $roomId = $payload->int('roomId');

        return null !== $roomId ? $rooms->find($roomId) : null;
    }

    private function cause(JsonRequestPayload $payload): ?EquipmentIncidentCause
    {
        return EquipmentIncidentCause::tryFrom($payload->string('cause'));
    }

    private function note(JsonRequestPayload $payload): ?string
    {
        $note = trim($payload->string('note'));

        return '' === $note ? null : $note;
    }

    /** Today means now, so the journal keeps its order; an earlier day is that day, never a future one. */
    private function occurredAt(JsonRequestPayload $payload): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->string('date'));

        if (false === $day || $day->format('Y-m-d') >= $now->format('Y-m-d')) {
            return $now;
        }

        return $day->setTime(12, 0);
    }

    private function refusal(string $messageKey): JsonResponse
    {
        return $this->json(['error' => $this->translator->trans($messageKey)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
