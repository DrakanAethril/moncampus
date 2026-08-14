<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What the printed loan conventions need: laptop serial number and replacement value, loan type, and the accessories lent and returned';
    }

    public function up(Schema $schema): void
    {
        // No temporary column DEFAULT here, unlike the usual reflex for a NOT NULL column landing on
        // a populated table: serial_number is unique, so a placeholder shared by every existing row
        // would collide on the index the very next statement creates. Production holds no laptop at
        // all; a development machine that has some must delete its laptop_loan rows and then its
        // laptop rows before migrating - in that order, the loans reference the laptops.
        $this->addSql('ALTER TABLE laptop ADD serial_number VARCHAR(255) NOT NULL, ADD replacement_value NUMERIC(10, 2) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_laptop_serial_number ON laptop (serial_number)');

        $this->addSql('ALTER TABLE laptop_loan ADD loan_type VARCHAR(20) NOT NULL, ADD lent_accessories LONGTEXT DEFAULT NULL, ADD lent_accessory_condition_type_id INT DEFAULT NULL, ADD return_accessory_condition_type_id INT DEFAULT NULL, ADD return_accessory_notes LONGTEXT DEFAULT NULL');
        // Doctrine's own generated names, so that schema:validate has nothing to rename.
        $this->addSql('ALTER TABLE laptop_loan ADD CONSTRAINT FK_FFDD3D2A7C90E412 FOREIGN KEY (lent_accessory_condition_type_id) REFERENCES laptop_condition_type (id)');
        $this->addSql('ALTER TABLE laptop_loan ADD CONSTRAINT FK_FFDD3D2A86CD60A1 FOREIGN KEY (return_accessory_condition_type_id) REFERENCES laptop_condition_type (id)');
        $this->addSql('CREATE INDEX IDX_FFDD3D2A7C90E412 ON laptop_loan (lent_accessory_condition_type_id)');
        $this->addSql('CREATE INDEX IDX_FFDD3D2A86CD60A1 ON laptop_loan (return_accessory_condition_type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2A7C90E412');
        $this->addSql('ALTER TABLE laptop_loan DROP FOREIGN KEY FK_FFDD3D2A86CD60A1');
        $this->addSql('DROP INDEX IDX_FFDD3D2A7C90E412 ON laptop_loan');
        $this->addSql('DROP INDEX IDX_FFDD3D2A86CD60A1 ON laptop_loan');
        $this->addSql('ALTER TABLE laptop_loan DROP loan_type, DROP lent_accessories, DROP lent_accessory_condition_type_id, DROP return_accessory_condition_type_id, DROP return_accessory_notes');

        $this->addSql('DROP INDEX uniq_laptop_serial_number ON laptop');
        $this->addSql('ALTER TABLE laptop DROP serial_number, DROP replacement_value');
    }
}
