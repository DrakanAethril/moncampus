<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A laptop loan records the formation its convention prints: name, start date, end date.
 *
 * The lend form proposes the three from the borrower's programme and the operator corrects them;
 * what is saved is what the paper says. Nothing is backfilled on purpose: a loan recorded before
 * this keeps NULL in `program_name`, which is how the printing tells it apart and goes on reading
 * the borrower's programme for it, as it always did.
 */
final class Version20261012120402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record on a laptop loan the formation name and dates printed on its convention';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop_loan ADD program_name VARCHAR(255) DEFAULT NULL, ADD program_start_date DATE DEFAULT NULL, ADD program_end_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop_loan DROP program_name, DROP program_start_date, DROP program_end_date');
    }
}
