<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\ProgramStudentOption;
use App\Service\ClassImport\ClassImportContextFactory;
use App\Service\ClassImport\NameKey;
use App\Service\ClassImport\StudentRow;
use App\Service\LdapManageUserRoleResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one part of the class import that talks to Doctrine. Its four queries are hand-written DQL,
 * which neither PHPStan nor lint:container can prove parses - so it is exercised here, against the
 * real (empty) test schema, rather than discovered from a 500 on the verification screen.
 */
class ClassImportContextFactoryTest extends FunctionalTestCase
{
    public function testReadsTheClassItsValuesAndTheAccountsThatCarryTheseNames(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new ClassImportContextFactory($entityManager, new LdapManageUserRoleResolver());

        $enrolled = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'mdupont');
        $enrolled->setFirstname('Martin');
        $enrolled->setLastname('Dupont');

        $elsewhere = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'adurand');
        $elsewhere->setFirstname('Alice');
        $elsewhere->setLastname('Durand');
        $elsewhere->setContactEmail('alice@example.org');

        $program = $this->createProgram([$enrolled]);

        $option = new Option('Solutions logicielles', 'SLAM', '#112233');
        $option->addProgram($program);
        $option->setCreatedBy($enrolled);
        $entityManager->persist($option);

        $modality = new Modality('Alternance', '#445566');
        $modality->addProgram($program);
        $modality->setCreatedBy($enrolled);
        $entityManager->persist($modality);

        $entityManager->flush();

        $entityManager->persist(new ProgramStudentOption($program, $enrolled, $option));
        $entityManager->flush();

        $context = $factory->build($program, [
            new StudentRow(2, 'DUPONT', 'martin', '', []),
            new StudentRow(3, 'Durand', 'Alice', 'alice@example.org', []),
            new StudentRow(4, 'Inconnu', 'Personne', 'nobody@example.org', []),
        ]);

        self::assertSame('TEST-1', $context->programLabel);
        self::assertSame(['Solutions logicielles'], array_map(static fn ($value): string => $value->label, $context->options));
        self::assertSame(['Alternance'], array_map(static fn ($value): string => $value->label, $context->modalities));

        $found = $context->accountsNamed(NameKey::of('Martin', 'Dupont'));
        self::assertCount(1, $found);
        self::assertTrue($found[0]->inDestinationProgram);
        self::assertTrue($found[0]->isStudent());
        self::assertTrue($found[0]->active);
        self::assertSame('TEST-1', $found[0]->programLabel);
        self::assertSame([$option->getId()], $found[0]->optionIds);
        self::assertSame([], $found[0]->modalityIds);

        $outside = $context->accountsNamed(NameKey::of('Alice', 'Durand'));
        self::assertCount(1, $outside);
        self::assertFalse($outside[0]->inDestinationProgram);

        self::assertSame([], $context->accountsNamed(NameKey::of('Personne', 'Inconnu')));
        self::assertSame($elsewhere->getId(), $context->ownerOfEmail('ALICE@example.org')[0] ?? null);
        self::assertNull($context->ownerOfEmail('nobody@example.org'));
    }
}
