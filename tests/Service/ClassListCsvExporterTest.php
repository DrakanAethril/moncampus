<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\User;
use App\Service\ClassImport\ClassImportCsvReader;
use App\Service\ClassListCsvExporter;
use App\Service\ClassRoster;
use PHPUnit\Framework\TestCase;

/**
 * The claim worth testing is the round trip: what this writes, App\Service\ClassImport\
 * ClassImportCsvReader has to be able to read back. Two of the cases below are there because
 * getting them wrong produces a file that opens fine and is refused on import - a student with two
 * options, and a class with none at all.
 */
class ClassListCsvExporterTest extends TestCase
{
    private ClassListCsvExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new ClassListCsvExporter(new ClassRoster());
    }

    public function testStudentsCarryTheImportsThreeNamedColumns(): void
    {
        $csv = $this->exporter->students([$this->student(1, 'Martin', 'Dupont', 'martin.dupont@example.org')], [], []);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel reads the accents from the BOM alone.');
        self::assertStringContainsString('nom;prenom;mail;option;modalite', $csv);
        self::assertStringContainsString('Dupont;Martin;martin.dupont@example.org', $csv);
    }

    public function testAnExportedClassIsReadableByTheImport(): void
    {
        $slam = $this->option('SLAM', 'Solutions logicielles');
        $alternance = $this->modality('ALT', 'Alternance');

        $csv = $this->exporter->students(
            [$this->student(1, 'Martin', 'Dupont', 'martin.dupont@example.org')],
            [1 => [$slam]],
            [1 => [$alternance]],
        );

        $rows = (new ClassImportCsvReader())->read($csv);

        self::assertCount(1, $rows);
        self::assertSame('Dupont', $rows[0]->lastname);
        self::assertSame('Martin', $rows[0]->firstname);
        self::assertSame('martin.dupont@example.org', $rows[0]->email);
        self::assertSame(['SLAM', 'ALT'], array_map(static fn ($cell): string => $cell->value, $rows[0]->freeCells));
    }

    public function testASecondOptionGetsItsOwnColumnRatherThanASharedCell(): void
    {
        $csv = $this->exporter->students(
            [$this->student(1, 'Martin', 'Dupont', 'martin.dupont@example.org')],
            [1 => [$this->option('SLAM', 'Solutions logicielles'), $this->option('SISR', 'Infrastructure')]],
            [],
        );

        self::assertStringContainsString('nom;prenom;mail;option;option;modalite', $csv);

        $rows = (new ClassImportCsvReader())->read($csv);
        self::assertSame(['SLAM', 'SISR', ''], array_map(static fn ($cell): string => $cell->value, $rows[0]->freeCells));
    }

    public function testAStudentWithoutOptionLeavesTheCellEmptyRatherThanShiftingTheRow(): void
    {
        $csv = $this->exporter->students(
            [
                $this->student(1, 'Martin', 'Dupont', 'martin.dupont@example.org'),
                $this->student(2, 'Chloé', 'Perrin', 'chloe.perrin@example.org'),
            ],
            [1 => [$this->option('SLAM', 'Solutions logicielles')]],
            [],
        );

        $rows = (new ClassImportCsvReader())->read($csv);

        self::assertSame('SLAM', $rows[0]->freeCells[0]->value);
        self::assertSame('', $rows[1]->freeCells[0]->value);
        self::assertSame('Perrin', $rows[1]->lastname);
    }

    public function testAnAccountLDAPNeverNamedFallsBackOnItsLoginRatherThanAnEmptyRow(): void
    {
        // Neither givenName nor sn: getDisplayName() answers null, and a row of two empty cells and
        // an address names nobody.
        $user = new User('jdoe');
        $user->setContactEmail('john.doe@example.org');

        $csv = $this->exporter->teachers([$user]);

        self::assertStringContainsString('jdoe;;john.doe@example.org', $csv);
    }

    public function testTheFileCarriesTheContactAddressRatherThanTheDirectorysOwn(): void
    {
        // User::$email is LDAP's internal address, rewritten on every login and not necessarily
        // read by anyone; the import matches an account on its contact address alone, so writing
        // the other one produces a file that round-trips to nobody.
        $csv = $this->exporter->students([$this->student(1, 'Martin', 'Dupont', 'martin.dupont@example.org')], [], []);

        self::assertStringContainsString('martin.dupont@example.org', $csv);
        self::assertStringNotContainsString('@ldap.', $csv);
    }

    public function testTeachersCarryTheThreeColumnsAndNothingElse(): void
    {
        $csv = $this->exporter->teachers([$this->student(1, 'Martin', 'Dupont', 'martin.dupont@example.org')]);

        self::assertStringContainsString('nom;prenom;mail', $csv);
        self::assertStringNotContainsString('option', $csv);
        self::assertStringNotContainsString('modalite', $csv);
    }

    public function testASeparatorInsideANameIsQuotedRatherThanSplittingTheRow(): void
    {
        $csv = $this->exporter->teachers([$this->student(1, 'Anne-Marie', 'Durand;Petit', 'am@example.org')]);

        $rows = (new ClassImportCsvReader())->read($csv);

        self::assertSame('Durand;Petit', $rows[0]->lastname);
    }

    private function student(int $id, string $firstname, string $lastname, string $email): User
    {
        $user = new User(strtolower($firstname.'.'.$lastname));
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setContactEmail($email);
        // The directory's own address, deliberately different: the file must carry the contact one.
        $user->setEmail(str_replace('@', '@ldap.', $email));

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function option(string $shortName, string $name): Option
    {
        return new Option($name, $shortName, '#1f4e79');
    }

    private function modality(string $shortName, string $name): Modality
    {
        $modality = new Modality($name, '#1f4e79');
        $modality->setShortName($shortName);

        return $modality;
    }
}
