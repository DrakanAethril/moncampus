<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rewards - design/validated/gamification.md, lot 6.
 *
 * Two tables, a catalogue and its grants, and **neither of them can move an index**: there is no
 * points column anywhere here, on purpose. If a reward gave points, a teacher could lift a student
 * above the others with one click and outside their envelope, and the equity of §2 would leave
 * through that door.
 *
 * The four tiers - bronze, silver, gold, trophy - are seeded as entries of the same catalogue with
 * `program_id` NULL, so they exist for every formation and are granted at closure by their
 * `automatic_threshold`. That threshold is an **index**, never a total of points.
 *
 * `used_at` / `used_on` are the consumable's half: the student spends it themselves, the teacher is
 * only told. A joker one can refuse is not a reward, it is a request.
 */
final class Version20260827180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reward catalogue and grants, with the four tiers as ordinary automatic entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reward_item (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, nature VARCHAR(20) NOT NULL, scope VARCHAR(20) NOT NULL, icon VARCHAR(8) DEFAULT NULL, automatic_threshold INT DEFAULT NULL, quantity INT DEFAULT NULL, active TINYINT NOT NULL, tier_code VARCHAR(20) DEFAULT NULL, program_id INT DEFAULT NULL, INDEX idx_reward_item_program (program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reward_grant (id INT AUTO_INCREMENT NOT NULL, group_ref INT DEFAULT NULL, granted_at DATETIME NOT NULL, reason LONGTEXT DEFAULT NULL, used_at DATETIME DEFAULT NULL, used_on VARCHAR(255) DEFAULT NULL, item_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, student_id INT DEFAULT NULL, granted_by_id INT DEFAULT NULL, INDEX IDX_AED93D10126F525E (item_id), INDEX IDX_AED93D103EB8070A (program_id), INDEX IDX_AED93D10EC8B7ADE (period_id), INDEX IDX_AED93D103151C11F (granted_by_id), INDEX idx_reward_grant_student (student_id), INDEX idx_reward_grant_period (program_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reward_item ADD CONSTRAINT FK_1E0AB5853EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reward_grant ADD CONSTRAINT FK_AED93D10126F525E FOREIGN KEY (item_id) REFERENCES reward_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reward_grant ADD CONSTRAINT FK_AED93D103EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reward_grant ADD CONSTRAINT FK_AED93D10EC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reward_grant ADD CONSTRAINT FK_AED93D10CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reward_grant ADD CONSTRAINT FK_AED93D103151C11F FOREIGN KEY (granted_by_id) REFERENCES `user` (id) ON DELETE SET NULL');

        // The four tiers of §5.5, establishment-wide (program_id NULL) and granted on the index.
        // The trophy has no threshold: it goes to the head of the class, which is a rank rather than
        // a value, and the closure grants it by itself.
        $this->addSql("INSERT INTO reward_item (program_id, label, description, nature, scope, icon, automatic_threshold, quantity, active, tier_code) VALUES (NULL, 'Cadre bronze', 'Octroyée automatiquement à l''indice 40. Cadre bronze sur l''avatar et mention au mur de la promo.', 'symbolic', 'student', '🥉', 40, NULL, 1, 'bronze')");
        $this->addSql("INSERT INTO reward_item (program_id, label, description, nature, scope, icon, automatic_threshold, quantity, active, tier_code) VALUES (NULL, 'Cadre argent', 'Octroyée automatiquement à l''indice 65. Cadre argent, thèmes d''avatar et titre affiché au choix.', 'symbolic', 'student', '🥈', 65, NULL, 1, 'silver')");
        $this->addSql("INSERT INTO reward_item (program_id, label, description, nature, scope, icon, automatic_threshold, quantity, active, tier_code) VALUES (NULL, 'Cadre or', 'Octroyée automatiquement à l''indice 85. Cadre or, un joker 24 h et le choix du sujet ou du binôme sur un TP.', 'symbolic', 'student', '🥇', 85, NULL, 1, 'gold')");
        $this->addSql("INSERT INTO reward_item (program_id, label, description, nature, scope, icon, automatic_threshold, quantity, active, tier_code) VALUES (NULL, 'Trophée de promo', 'Octroyé à la tête de classe à la clôture, et mis à l''honneur à la remise des bulletins.', 'symbolic', 'student', '🏆', NULL, NULL, 1, 'trophy')");
        $this->addSql("INSERT INTO reward_item (program_id, label, description, nature, scope, icon, automatic_threshold, quantity, active, tier_code) VALUES (NULL, 'Joker 24 h', 'Reporte d''un jour un travail à rendre. Jamais sur une évaluation notée.', 'consumable', 'student', '🃏', NULL, NULL, 1, NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reward_grant');
        $this->addSql('DROP TABLE reward_item');
    }
}
