<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brings every e-CO GPS fix logged so far into the server's zone.
 *
 * The app sends each fix's time in UTC (`…Z`) and the API stored it as it came: Doctrine writes a
 * DATETIME as the object's wall time, so the column held UTC wall time, read back as Paris time -
 * every fix two hours (one in winter) before the scans it sits between. The API now converts on
 * arrival (JsonRequestPayload::instant()); this moves what was written before.
 *
 * The offset is not one number: it follows the zone's own changes, summer time and winter time,
 * read from PHP's zone database rather than from MySQL's time-zone tables, which a server may
 * never have loaded. One UPDATE with a CASE rather than one per period, so each row is shifted
 * once from its original value - a row moved forward can never be caught again by the next
 * period's range.
 *
 * Every row present when this runs was written by the old API, so every one moves. Irreversible:
 * the shifted values no longer say which period they came from.
 */
final class Version20260929114500 extends AbstractMigration
{
    /** Before the first release of the e-CO app: no fix is older. */
    private const string FIRST_POSSIBLE_FIX = '2026-01-01 00:00:00';

    public function getDescription(): string
    {
        return "e-CO : les heures des positions GPS passent de l'heure UTC à l'heure du serveur";
    }

    public function up(Schema $schema): void
    {
        $utc = new \DateTimeZone('UTC');
        $zone = new \DateTimeZone(date_default_timezone_get());
        $from = new \DateTimeImmutable(self::FIRST_POSSIBLE_FIX, $utc);
        $until = (new \DateTimeImmutable('now', $utc))->modify('+1 day');

        $transitions = $zone->getTransitions($from->getTimestamp(), $until->getTimestamp());
        if (false === $transitions || [] === $transitions) {
            $transitions = [['ts' => $from->getTimestamp(), 'offset' => $zone->getOffset($from)]];
        }

        // $transitions[0] is the state at $from; each later entry opens a new period at its 'ts'.
        $cases = [];
        for ($i = 0, $count = \count($transitions); $i < $count - 1; ++$i) {
            $boundary = (new \DateTimeImmutable('@'.$transitions[$i + 1]['ts']))->format('Y-m-d H:i:s');
            $cases[] = \sprintf("WHEN recorded_at < '%s' THEN DATE_ADD(recorded_at, INTERVAL %d SECOND)", $boundary, $transitions[$i]['offset']);
        }
        $lastOffset = $transitions[\count($transitions) - 1]['offset'];

        $this->addSql(\sprintf(
            'UPDATE eco_position_ping SET recorded_at = CASE %s ELSE DATE_ADD(recorded_at, INTERVAL %d SECOND) END',
            implode(' ', $cases),
            $lastOffset,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
