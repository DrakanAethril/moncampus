<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706053157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add createdBy/inactivatedBy/lastUpdatedBy (User relations) and lastUpdatedDate to every structure entity';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cohort ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cohort ADD CONSTRAINT FK_D3B8C16BB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE cohort ADD CONSTRAINT FK_D3B8C16BF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE cohort ADD CONSTRAINT FK_D3B8C16BE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_D3B8C16BB03A8386 ON cohort (created_by_id)');
        $this->addSql('CREATE INDEX IDX_D3B8C16BF5A2E305 ON cohort (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_D3B8C16BE562D849 ON cohort (last_updated_by_id)');
        $this->addSql('ALTER TABLE modality ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE modality ADD CONSTRAINT FK_307988C0B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE modality ADD CONSTRAINT FK_307988C0F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE modality ADD CONSTRAINT FK_307988C0E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_307988C0B03A8386 ON modality (created_by_id)');
        $this->addSql('CREATE INDEX IDX_307988C0F5A2E305 ON modality (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_307988C0E562D849 ON modality (last_updated_by_id)');
        $this->addSql('ALTER TABLE `option` ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `option` ADD CONSTRAINT FK_5A8600B0B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE `option` ADD CONSTRAINT FK_5A8600B0F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE `option` ADD CONSTRAINT FK_5A8600B0E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_5A8600B0B03A8386 ON `option` (created_by_id)');
        $this->addSql('CREATE INDEX IDX_5A8600B0F5A2E305 ON `option` (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_5A8600B0E562D849 ON `option` (last_updated_by_id)');
        $this->addSql('ALTER TABLE period ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE period ADD CONSTRAINT FK_C5B81ECEB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period ADD CONSTRAINT FK_C5B81ECEF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period ADD CONSTRAINT FK_C5B81ECEE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_C5B81ECEB03A8386 ON period (created_by_id)');
        $this->addSql('CREATE INDEX IDX_C5B81ECEF5A2E305 ON period (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_C5B81ECEE562D849 ON period (last_updated_by_id)');
        $this->addSql('ALTER TABLE program ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED7784B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED7784F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED7784E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_92ED7784B03A8386 ON program (created_by_id)');
        $this->addSql('CREATE INDEX IDX_92ED7784F5A2E305 ON program (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_92ED7784E562D849 ON program (last_updated_by_id)');
        $this->addSql('ALTER TABLE room ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE room ADD CONSTRAINT FK_729F519BB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE room ADD CONSTRAINT FK_729F519BF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE room ADD CONSTRAINT FK_729F519BE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_729F519BB03A8386 ON room (created_by_id)');
        $this->addSql('CREATE INDEX IDX_729F519BF5A2E305 ON room (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_729F519BE562D849 ON room (last_updated_by_id)');
        $this->addSql('ALTER TABLE school_year ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE school_year ADD CONSTRAINT FK_FAAAACDAB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE school_year ADD CONSTRAINT FK_FAAAACDAF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE school_year ADD CONSTRAINT FK_FAAAACDAE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_FAAAACDAB03A8386 ON school_year (created_by_id)');
        $this->addSql('CREATE INDEX IDX_FAAAACDAF5A2E305 ON school_year (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_FAAAACDAE562D849 ON school_year (last_updated_by_id)');
        $this->addSql('ALTER TABLE section ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEFB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEFF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEFE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_2D737AEFB03A8386 ON section (created_by_id)');
        $this->addSql('CREATE INDEX IDX_2D737AEFF5A2E305 ON section (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_2D737AEFE562D849 ON section (last_updated_by_id)');
        $this->addSql('ALTER TABLE track ADD last_updated_date DATETIME DEFAULT NULL, ADD created_by_id INT NOT NULL, ADD inactivated_by_id INT DEFAULT NULL, ADD last_updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A6B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A6F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A6E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_D6E3F8A6B03A8386 ON track (created_by_id)');
        $this->addSql('CREATE INDEX IDX_D6E3F8A6F5A2E305 ON track (inactivated_by_id)');
        $this->addSql('CREATE INDEX IDX_D6E3F8A6E562D849 ON track (last_updated_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cohort DROP FOREIGN KEY FK_D3B8C16BB03A8386');
        $this->addSql('ALTER TABLE cohort DROP FOREIGN KEY FK_D3B8C16BF5A2E305');
        $this->addSql('ALTER TABLE cohort DROP FOREIGN KEY FK_D3B8C16BE562D849');
        $this->addSql('DROP INDEX IDX_D3B8C16BB03A8386 ON cohort');
        $this->addSql('DROP INDEX IDX_D3B8C16BF5A2E305 ON cohort');
        $this->addSql('DROP INDEX IDX_D3B8C16BE562D849 ON cohort');
        $this->addSql('ALTER TABLE cohort DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE modality DROP FOREIGN KEY FK_307988C0B03A8386');
        $this->addSql('ALTER TABLE modality DROP FOREIGN KEY FK_307988C0F5A2E305');
        $this->addSql('ALTER TABLE modality DROP FOREIGN KEY FK_307988C0E562D849');
        $this->addSql('DROP INDEX IDX_307988C0B03A8386 ON modality');
        $this->addSql('DROP INDEX IDX_307988C0F5A2E305 ON modality');
        $this->addSql('DROP INDEX IDX_307988C0E562D849 ON modality');
        $this->addSql('ALTER TABLE modality DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE `option` DROP FOREIGN KEY FK_5A8600B0B03A8386');
        $this->addSql('ALTER TABLE `option` DROP FOREIGN KEY FK_5A8600B0F5A2E305');
        $this->addSql('ALTER TABLE `option` DROP FOREIGN KEY FK_5A8600B0E562D849');
        $this->addSql('DROP INDEX IDX_5A8600B0B03A8386 ON `option`');
        $this->addSql('DROP INDEX IDX_5A8600B0F5A2E305 ON `option`');
        $this->addSql('DROP INDEX IDX_5A8600B0E562D849 ON `option`');
        $this->addSql('ALTER TABLE `option` DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE period DROP FOREIGN KEY FK_C5B81ECEB03A8386');
        $this->addSql('ALTER TABLE period DROP FOREIGN KEY FK_C5B81ECEF5A2E305');
        $this->addSql('ALTER TABLE period DROP FOREIGN KEY FK_C5B81ECEE562D849');
        $this->addSql('DROP INDEX IDX_C5B81ECEB03A8386 ON period');
        $this->addSql('DROP INDEX IDX_C5B81ECEF5A2E305 ON period');
        $this->addSql('DROP INDEX IDX_C5B81ECEE562D849 ON period');
        $this->addSql('ALTER TABLE period DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784B03A8386');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784F5A2E305');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784E562D849');
        $this->addSql('DROP INDEX IDX_92ED7784B03A8386 ON program');
        $this->addSql('DROP INDEX IDX_92ED7784F5A2E305 ON program');
        $this->addSql('DROP INDEX IDX_92ED7784E562D849 ON program');
        $this->addSql('ALTER TABLE program DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE room DROP FOREIGN KEY FK_729F519BB03A8386');
        $this->addSql('ALTER TABLE room DROP FOREIGN KEY FK_729F519BF5A2E305');
        $this->addSql('ALTER TABLE room DROP FOREIGN KEY FK_729F519BE562D849');
        $this->addSql('DROP INDEX IDX_729F519BB03A8386 ON room');
        $this->addSql('DROP INDEX IDX_729F519BF5A2E305 ON room');
        $this->addSql('DROP INDEX IDX_729F519BE562D849 ON room');
        $this->addSql('ALTER TABLE room DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE school_year DROP FOREIGN KEY FK_FAAAACDAB03A8386');
        $this->addSql('ALTER TABLE school_year DROP FOREIGN KEY FK_FAAAACDAF5A2E305');
        $this->addSql('ALTER TABLE school_year DROP FOREIGN KEY FK_FAAAACDAE562D849');
        $this->addSql('DROP INDEX IDX_FAAAACDAB03A8386 ON school_year');
        $this->addSql('DROP INDEX IDX_FAAAACDAF5A2E305 ON school_year');
        $this->addSql('DROP INDEX IDX_FAAAACDAE562D849 ON school_year');
        $this->addSql('ALTER TABLE school_year DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEFB03A8386');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEFF5A2E305');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEFE562D849');
        $this->addSql('DROP INDEX IDX_2D737AEFB03A8386 ON section');
        $this->addSql('DROP INDEX IDX_2D737AEFF5A2E305 ON section');
        $this->addSql('DROP INDEX IDX_2D737AEFE562D849 ON section');
        $this->addSql('ALTER TABLE section DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
        $this->addSql('ALTER TABLE track DROP FOREIGN KEY FK_D6E3F8A6B03A8386');
        $this->addSql('ALTER TABLE track DROP FOREIGN KEY FK_D6E3F8A6F5A2E305');
        $this->addSql('ALTER TABLE track DROP FOREIGN KEY FK_D6E3F8A6E562D849');
        $this->addSql('DROP INDEX IDX_D6E3F8A6B03A8386 ON track');
        $this->addSql('DROP INDEX IDX_D6E3F8A6F5A2E305 ON track');
        $this->addSql('DROP INDEX IDX_D6E3F8A6E562D849 ON track');
        $this->addSql('ALTER TABLE track DROP last_updated_date, DROP created_by_id, DROP inactivated_by_id, DROP last_updated_by_id');
    }
}
