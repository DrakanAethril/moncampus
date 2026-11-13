<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UFA: the activity feed writes a student surname first, like the rest of the UFA. The sentences
 * already recorded keep a snapshot of the names (UfaActivity::$payload), so they are rewritten
 * only where the snapshot is exactly the student's « Prénom Nom » of today - a name that changed
 * since stays as it was written.
 */
final class Version20261113090000 extends AbstractMigration
{
    private const string FIRST_LAST = "TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')))";
    private const string LAST_FIRST = "TRIM(CONCAT(COALESCE(u.lastname, ''), ' ', COALESCE(u.firstname, '')))";

    public function getDescription(): string
    {
        return 'UFA: the activity feed names the student surname first';
    }

    public function up(Schema $schema): void
    {
        $this->rewrite(self::FIRST_LAST, self::LAST_FIRST);
    }

    public function down(Schema $schema): void
    {
        $this->rewrite(self::LAST_FIRST, self::FIRST_LAST);
    }

    private function rewrite(string $from, string $to): void
    {
        foreach (['student' => '1 = 1', 'actor' => 'a.actor_id = l.student_id'] as $key => $condition) {
            $this->addSql(\sprintf(
                "UPDATE ufa_activity a JOIN internship_tutor_link l ON l.id = a.tutor_link_id JOIN `user` u ON u.id = l.student_id
                 SET a.payload = JSON_SET(a.payload, '$.%1\$s', %3\$s)
                 WHERE %4\$s AND (u.firstname IS NOT NULL OR u.lastname IS NOT NULL)
                   AND JSON_UNQUOTE(JSON_EXTRACT(a.payload, '$.%1\$s')) = %2\$s",
                $key,
                $from,
                $to,
                $condition,
            ));
        }
    }
}
