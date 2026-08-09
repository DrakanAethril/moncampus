<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\EmailAliasRepository;
use App\Service\StudentMailAddressGenerator;
use PHPUnit\Framework\TestCase;

/**
 * La règle de composition des adresses « prenom.nom » du Courrier école.
 *
 * Elle mérite d'être épinglée ici plus que la moyenne, pour une raison qui n'a rien de technique :
 * l'adresse produite finit imprimée sur des CV et enregistrée dans les carnets d'adresses
 * d'entreprises. Une fois émise, elle ne se change pas. Un changement discret de translittération
 * ne casserait donc aucun test d'écran, mais produirait deux générations d'élèves aux conventions
 * différentes - et c'est irrattrapable.
 */
class StudentMailAddressGeneratorTest extends TestCase
{
    public function testAccentsAreFoldedAndCaseLowered(): void
    {
        self::assertSame('chloe.dupont', $this->generate('Chloé', 'Dupont'));

        // Le repli est un simple dépouillement de diacritique, pas une romanisation à l'allemande :
        // `Ü` donne `u` et non `ue`. Même mécanisme (iconv //TRANSLIT) que App\Service\LoginGenerator,
        // donc login et adresse restent cohérents pour un même élève.
        self::assertSame('joel.muller', $this->generate('JOËL', 'MÜLLER'));
    }

    public function testHyphensSurviveButSpacesGlueParticles(): void
    {
        // Un prénom composé garde son tiret, une particule se colle au nom : c'est la règle
        // arrêtée avec l'établissement, et les deux moitiés ne se traitent pas pareil.
        self::assertSame('jean-pierre.legall', $this->generate('Jean-Pierre', 'Le Gall'));
        self::assertSame('shirine.elhani', $this->generate('Shirine', 'El Hani'));
    }

    public function testOnlyTheFirstGivenNameIsKept(): void
    {
        // L'espace ne veut pas dire la même chose des deux côtés du point : dans un nom il sépare
        // une particule de son nom, qui forment un tout et se collent ; dans un prénom il sépare
        // des prénoms d'état civil, dont seul le premier est le prénom d'usage.
        self::assertSame('mouhamadoun.waigalo', $this->generate('Mouhamadoun Aly', 'Waigalo'));
        self::assertSame('tity.bassekanounga', $this->generate('Tity Gabriel', 'Basseka Nounga'));

        // Le tiret reste un prénom composé, pas deux prénoms : il ne déclenche pas la troncature.
        self::assertSame('jean-pierre.martin', $this->generate('Jean-Pierre', 'Martin'));
    }

    public function testApostrophesDisappear(): void
    {
        self::assertSame('chloe.darcy', $this->generate('Chloé', "d'Arcy"));
        self::assertSame('marie.ohara', $this->generate('Marie', "O'Hara"));
    }

    public function testStrayHyphensNeverLeakIntoTheAddress(): void
    {
        // Une saisie approximative ne doit pas produire une adresse mal formée, qui serait
        // rejetée par certains serveurs de messagerie.
        self::assertSame('jean.martin', $this->generate('-Jean-', '--Martin--'));
    }

    public function testAMissingHalfStillYieldsAUsableAddress(): void
    {
        // Ni point orphelin en tête, ni en queue : un dossier incomplet donne quand même une
        // adresse valide, quitte à être moins lisible.
        self::assertSame('martin', $this->generate('', 'Martin'));
        self::assertSame('jean', $this->generate('Jean', ''));
    }

    public function testRealHomonymsAreNumberedFromTwo(): void
    {
        $generator = $this->generatorWithExisting(['camille.roux']);

        self::assertSame('camille.roux2', $generator->generateFor($this->user('Camille', 'Roux')));
    }

    public function testTwoHomonymsInTheSameBatchDoNotCollide(): void
    {
        // Le contrôle d'unicité est une requête, donc le second élève d'un même lot ne verrait pas
        // le premier tant qu'aucun flush n'a eu lieu. Sans la réservation en mémoire, les deux
        // recevraient la même adresse et se heurteraient sur la contrainte unique.
        $generator = $this->generatorWithExisting([]);

        self::assertSame('camille.roux', $generator->generateFor($this->user('Camille', 'Roux')));
        self::assertSame('camille.roux2', $generator->generateFor($this->user('Camille', 'Roux')));
        self::assertSame('camille.roux3', $generator->generateFor($this->user('Camille', 'Roux')));
    }

    public function testServiceAddressesAreNeverHandedToAStudent(): void
    {
        $generator = $this->generatorWithExisting([]);

        // La réception est en catch-all : ces adresses sont celles du domaine, pas d'une personne.
        // `dmarc` est déjà servie par une règle SES qui range les rapports d'authentification ;
        // `postmaster` et `abuse` sont normalisées par la RFC 2142.
        self::assertFalse($generator->isAvailable('dmarc'));
        self::assertFalse($generator->isAvailable('postmaster'));
        self::assertFalse($generator->isAvailable('abuse'));

        // Et un élève dont le nom composerait l'une d'elles est numéroté, pas refusé.
        self::assertSame('abuse2', $generator->generateFor($this->user('', 'Abuse')));
    }

    public function testAnEmptyNameIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->generate('', '');
    }

    private function generate(string $firstname, string $lastname): string
    {
        return $this->generatorWithExisting([])->generateFor($this->user($firstname, $lastname));
    }

    /** @param list<string> $existing */
    private function generatorWithExisting(array $existing): StudentMailAddressGenerator
    {
        // Un stub et non un mock : on ne vérifie pas *comment* le dépôt est appelé, seulement ce
        // qu'il répond - le sujet du test est la règle de composition, pas les accès en base.
        $repository = $this->createStub(EmailAliasRepository::class);
        $repository->method('localPartExists')
            ->willReturnCallback(static fn (string $localPart): bool => \in_array($localPart, $existing, true));

        return new StudentMailAddressGenerator($repository);
    }

    private function user(string $firstname, string $lastname): User
    {
        $user = new User('test');
        $user->setFirstname($firstname);
        $user->setLastname($lastname);

        return $user;
    }
}
