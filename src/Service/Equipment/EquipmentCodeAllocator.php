<?php

declare(strict_types=1);

namespace App\Service\Equipment;

use Doctrine\DBAL\Connection;

/**
 * Hands out label numbers, a whole batch at once.
 *
 * One row, one atomic statement: the upsert takes the row lock and holds it until the caller's
 * transaction ends, so two people receiving mice at the same moment get two disjoint ranges, and a
 * batch that rolls back gives its numbers back rather than leaving a hole. It must therefore be
 * called inside a transaction - App\Service\Equipment\EquipmentLedger always does.
 *
 * Not the row id of the piece: the code is printed on an object and has to survive anything done to
 * the table, and it is numbered across every type, where an id would also be.
 */
final readonly class EquipmentCodeAllocator
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{int, int} the first and last number of the reserved range
     */
    public function reserve(int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Reserve at least one number.');
        }

        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Label numbers are reserved inside the transaction that uses them.');
        }

        $this->connection->executeStatement(
            'INSERT INTO equipment_code_sequence (id, next_number) VALUES (1, 1 + :count)
             ON DUPLICATE KEY UPDATE next_number = next_number + :count',
            ['count' => $count],
        );

        // Read back under the lock this transaction now holds: nobody else can have moved it.
        $next = $this->connection->fetchOne('SELECT next_number FROM equipment_code_sequence WHERE id = 1');

        if (!is_numeric($next)) {
            throw new \LogicException('The equipment code sequence row is missing.');
        }

        $next = (int) $next;

        return [$next - $count, $next - 1];
    }
}
