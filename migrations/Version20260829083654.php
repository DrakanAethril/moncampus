<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `user_login`: every login an account has ever answered to, and the reservation that goes with it
 * (App\Entity\UserLogin).
 *
 * The table is backfilled here rather than left to start empty, and the reconstruction is exact
 * rather than approximate - which is only possible because `ldap_manage_account` happens to record
 * **both** sides of every rename: `login` is what the account was called when the request was
 * posted, `new_login` what it was to become. So:
 *
 *  - every account contributes its current username, undated (nobody wrote down when it was taken);
 *  - every *applied* rename contributes the login it displaced, released on `applied_at`.
 *
 * A second rename's `login` is the first one's `new_login`, so the intermediate logins - the ones
 * that until now survived absolutely nowhere - come back too, and no third source is needed.
 * `ldap_manage_user.login` adds nothing: for an account that was renamed it is the first rename's
 * `login`, and for one that never was it is the current username.
 *
 * Both inserts skip a login already present. In a consistent database that can only be a rename
 * that was later reversed - the login is then the current username, inserted by the first statement
 * and rightly current. It is written defensively all the same: a UNIQUE violation here would abort
 * a production deploy over data nobody can fix at that moment, and dropping one reconstructed
 * historical row is a far smaller loss than that.
 */
final class Version20260829083654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Every login an account has held, reserved for it for ever - backfilled from the rename queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_login (id INT AUTO_INCREMENT NOT NULL, login VARCHAR(64) NOT NULL, assigned_at DATETIME DEFAULT NULL, released_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX idx_user_login_user (user_id), UNIQUE INDEX uniq_user_login_login (login), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_login ADD CONSTRAINT FK_48CA3048A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');

        // The login every account answers to today. `assigned_at` stays NULL: the date it was taken
        // is not recorded anywhere, and a fabricated one would read as fact.
        $this->addSql(<<<'SQL'
            INSERT INTO user_login (user_id, login, assigned_at, released_at)
            SELECT u.id, u.username, NULL, NULL
              FROM `user` u
             WHERE CHAR_LENGTH(u.username) <= 64
            SQL);

        // The logins renames displaced. Grouped by login rather than by (user, login) so that the
        // statement cannot itself produce two rows for one login, whatever the old data holds.
        $this->addSql(<<<'SQL'
            INSERT INTO user_login (user_id, login, assigned_at, released_at)
            SELECT former.user_id, former.login, NULL, former.released_at
              FROM (
                    SELECT login,
                           MIN(user_id)    AS user_id,
                           MAX(applied_at) AS released_at
                      FROM ldap_manage_account
                     WHERE action_type = 'login_change'
                       AND applied_at IS NOT NULL
                       AND CHAR_LENGTH(login) <= 64
                     GROUP BY login
                   ) former
              LEFT JOIN (SELECT login FROM user_login) taken ON taken.login = former.login
             WHERE taken.login IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_login DROP FOREIGN KEY FK_48CA3048A76ED395');
        $this->addSql('DROP TABLE user_login');
    }
}
