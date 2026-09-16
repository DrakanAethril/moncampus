<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A laptop loan may now run with no return date at all - the case of an internal loan, where a
 * machine is handed to a member of staff for as long as they need it and no convention is signed.
 *
 * Only the column changes: which loans are allowed to leave it empty is
 * LaptopLoanType::allowsIndefiniteDuration(), and LaptopLoan's own Assert\Callback is what still
 * demands a date on the two types whose paper prints one. Every existing row carries a date, so the
 * down() below can narrow the column back without losing anything.
 */
final class Version20260916090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prêt à durée indéfinie : laptop_loan.due_at devient facultatif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE laptop_loan CHANGE due_at due_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // A loan that was recorded with no return date has none to restore: it is dated back to its
        // own lending date, the only date the row actually holds.
        $this->addSql('UPDATE laptop_loan SET due_at = lent_at WHERE due_at IS NULL');
        $this->addSql('ALTER TABLE laptop_loan CHANGE due_at due_at DATETIME NOT NULL');
    }
}
