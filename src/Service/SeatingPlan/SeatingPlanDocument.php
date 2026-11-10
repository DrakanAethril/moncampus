<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use App\Enum\SeatingFurniture;
use App\Enum\SeatingRuleType;

/**
 * A seating plan, whole: the room, the rules, the two preferences, who sits where, the locked
 * seats and the seed the last proposal was drawn with (design/validated/plans-de-classe.md, §5).
 *
 * **fromArray() is the only door a document comes in by** - from the database and from the page
 * alike - and it refuses the whole document at the first element that breaks a rule of shape:
 * an unknown piece of furniture, two tables on one cell, a student seated twice. What it does
 * **not** refuse is a student who is no longer in the class: they are dropped from the seats, the
 * locks and the rules, silently, because the class moves under a saved plan and the plan must
 * still open. A rule left with fewer students than it needs is dropped with them.
 */
final readonly class SeatingPlanDocument
{
    public const int MAX_RULES = 60;
    private const string ID_PATTERN = '/^[a-z0-9-]{1,24}$/i';

    /**
     * @param list<SeatingRule>  $rules
     * @param array<string, int> $seats seat id => student id
     * @param list<string>       $pins  seat ids whose student a new proposal keeps
     */
    public function __construct(
        public SeatingRoom $room,
        public array $rules = [],
        public bool $mixOptions = true,
        public bool $frontFirst = true,
        public array $seats = [],
        public array $pins = [],
        public int $seed = 1,
    ) {
    }

    public static function forRoom(SeatingRoom $room): self
    {
        return new self($room);
    }

    /**
     * @param list<int> $rosterIds the students of the class today
     *
     * @throws InvalidSeatingPlanException
     */
    public static function fromArray(mixed $raw, array $rosterIds): self
    {
        if (!\is_array($raw)) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidDocumentError', 'The document is not an object.');
        }
        $roster = array_flip($rosterIds);

        $room = self::room($raw['room'] ?? null);
        $rules = self::rules($raw['rules'] ?? [], $roster);

        $preferences = \is_array($raw['preferences'] ?? null) ? $raw['preferences'] : [];
        $mix = $preferences['mixOptions'] ?? true;
        $front = $preferences['frontFirst'] ?? true;
        if (!\is_bool($mix) || !\is_bool($front)) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidDocumentError', 'A preference is not a boolean.');
        }

        $rawSeats = $raw['seats'] ?? [];
        if (!\is_array($rawSeats)) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidSeatsError', 'The seats are not an object.');
        }
        $seats = [];
        $seated = [];
        foreach ($rawSeats as $seatId => $studentId) {
            if (!\is_string($seatId) || !\is_int($studentId) || null === $room->seat($seatId)) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidSeatsError', \sprintf('Seat "%s" is not a seat of this room.', (string) $seatId));
            }
            if (!isset($roster[$studentId])) {
                continue;
            }
            if (isset($seated[$studentId])) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidSeatsError', \sprintf('Student %d is seated twice.', $studentId));
            }
            $seated[$studentId] = true;
            $seats[$seatId] = $studentId;
        }

        $rawPins = $raw['pins'] ?? [];
        if (!\is_array($rawPins)) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidSeatsError', 'The locked seats are not a list.');
        }
        $pins = [];
        foreach ($rawPins as $pin) {
            if (!\is_string($pin)) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidSeatsError', 'A locked seat is not a seat id.');
            }
            // A lock on an empty seat - its student left the class, or was moved off by hand -
            // keeps nothing, so it is not kept either.
            if (isset($seats[$pin]) && !\in_array($pin, $pins, true)) {
                $pins[] = $pin;
            }
        }

        $seed = $raw['seed'] ?? 1;
        if (!\is_int($seed)) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidDocumentError', 'The seed is not an integer.');
        }

        return new self($room, $rules, $mix, $front, $seats, $pins, max(1, min($seed, 2_000_000_000)));
    }

    public function withSeats(array $seats, int $seed): self
    {
        return new self($this->room, $this->rules, $this->mixOptions, $this->frontFirst, $seats, $this->pins, $seed);
    }

    public function withRoom(SeatingRoom $room): self
    {
        $seats = array_filter($this->seats, static fn (string $seatId): bool => null !== $room->seat($seatId), \ARRAY_FILTER_USE_KEY);

        return new self($room, $this->rules, $this->mixOptions, $this->frontFirst, $seats, array_values(array_filter($this->pins, static fn (string $pin): bool => isset($seats[$pin]))), $this->seed);
    }

    /**
     * @return array{room: array{columns: int, rows: int, tables: list<array{id: string, type: string, x: int, y: int, rotated: bool}>}, rules: list<array{id: string, type: string, students: list<int>}>, preferences: array{mixOptions: bool, frontFirst: bool}, seats: array<string, int>, pins: list<string>, seed: int}
     */
    public function toArray(): array
    {
        return [
            'room' => $this->room->toArray(),
            'rules' => array_map(static fn (SeatingRule $rule): array => $rule->toArray(), $this->rules),
            'preferences' => ['mixOptions' => $this->mixOptions, 'frontFirst' => $this->frontFirst],
            'seats' => $this->seats,
            'pins' => $this->pins,
            'seed' => $this->seed,
        ];
    }

    /** @throws InvalidSeatingPlanException */
    public static function room(mixed $raw): SeatingRoom
    {
        if (!\is_array($raw)) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidRoomError', 'The room is not an object.');
        }
        $columns = $raw['columns'] ?? null;
        $rows = $raw['rows'] ?? null;
        if (!\is_int($columns) || !\is_int($rows)
            || $columns < SeatingRoom::MIN_COLUMNS || $columns > SeatingRoom::MAX_COLUMNS
            || $rows < SeatingRoom::MIN_ROWS || $rows > SeatingRoom::MAX_ROWS) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidRoomError', 'The room dimensions are out of bounds.');
        }

        $rawTables = $raw['tables'] ?? [];
        if (!\is_array($rawTables) || !array_is_list($rawTables) || \count($rawTables) > SeatingRoom::MAX_TABLES) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidRoomError', 'The tables are not a list, or too long a one.');
        }

        $room = new SeatingRoom($columns, $rows, []);
        $tables = [];
        foreach ($rawTables as $index => $rawTable) {
            if (!\is_array($rawTable)) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidRoomError', \sprintf('Table %d is not an object.', $index));
            }
            $id = $rawTable['id'] ?? null;
            $type = \is_string($rawTable['type'] ?? null) ? SeatingFurniture::tryFrom($rawTable['type']) : null;
            $x = $rawTable['x'] ?? null;
            $y = $rawTable['y'] ?? null;
            $rotated = $rawTable['rotated'] ?? false;
            if (!\is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id) || null === $type || !\is_int($x) || !\is_int($y) || !\is_bool($rotated)) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidRoomError', \sprintf('Table %d is malformed.', $index));
            }
            if (isset($tables[$id])) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidRoomError', \sprintf('Table id "%s" is used twice.', $id));
            }
            $table = new SeatingTable($id, $type, $x, $y, $rotated && $type->canRotate());
            if (!$room->fits($table)) {
                throw new InvalidSeatingPlanException('seatingPlanOverlappingTablesError', \sprintf('Table "%s" is outside the room or on another one.', $id));
            }
            $tables[$id] = $table;
            $room = new SeatingRoom($columns, $rows, array_values($tables));
        }

        return $room;
    }

    /**
     * @param array<int, int> $roster
     *
     * @return list<SeatingRule>
     */
    private static function rules(mixed $raw, array $roster): array
    {
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) > self::MAX_RULES) {
            throw new InvalidSeatingPlanException('seatingPlanInvalidRulesError', 'The rules are not a list, or too long a one.');
        }

        $rules = [];
        $ids = [];
        foreach ($raw as $index => $rawRule) {
            $id = \is_array($rawRule) ? ($rawRule['id'] ?? null) : null;
            $type = \is_array($rawRule) && \is_string($rawRule['type'] ?? null) ? SeatingRuleType::tryFrom($rawRule['type']) : null;
            $students = \is_array($rawRule) ? ($rawRule['students'] ?? null) : null;
            if (!\is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id) || isset($ids[$id]) || null === $type || !\is_array($students) || !array_is_list($students)) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidRulesError', \sprintf('Rule %d is malformed.', $index));
            }
            $ids[$id] = true;

            $studentIds = [];
            foreach ($students as $studentId) {
                if (!\is_int($studentId)) {
                    throw new InvalidSeatingPlanException('seatingPlanInvalidRulesError', \sprintf('Rule "%s" names something that is not a student.', $id));
                }
                if (!\in_array($studentId, $studentIds, true)) {
                    $studentIds[] = $studentId;
                }
            }
            if (\count($studentIds) < $type->minStudents() || \count($studentIds) > $type->maxStudents()) {
                throw new InvalidSeatingPlanException('seatingPlanInvalidRulesError', \sprintf('Rule "%s" names %d students.', $id, \count($studentIds)));
            }

            $known = array_values(array_filter($studentIds, static fn (int $studentId): bool => isset($roster[$studentId])));
            if (\count($known) >= $type->minStudents()) {
                $rules[] = new SeatingRule($id, $type, $known);
            }
        }

        return $rules;
    }
}
