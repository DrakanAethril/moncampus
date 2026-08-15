<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Group;
use App\Entity\GroupType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The hierarchy rule, checked where it is actually applied: a group hangs off at most one other
 * group, of a different kind, above it and never below.
 *
 * App\Tests\Service\GroupHierarchyTest covers the walks over ids; here it is the entity's own
 * refusal that is pinned, since that is what every writer of a group goes through.
 */
class GroupParentValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    private GroupType $filiere;

    private GroupType $classe;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $this->filiere = $this->groupType(1, 'Filière');
        $this->classe = $this->groupType(2, 'Classe');
    }

    public function testAGroupUnderAGroupOfAnotherTypePasses(): void
    {
        $sio = new Group('sio', 'ROLE_SIO');
        $sio->setGroupType($this->filiere);

        $sio2 = new Group('sio-2', 'ROLE_SIO-2');
        $sio2->setGroupType($this->classe);
        $sio2->setParent($sio);

        self::assertCount(0, $this->validator->validate($sio2));
    }

    public function testNoParentAtAllPasses(): void
    {
        $campus = new Group('campus', 'ROLE_CAMPUS');
        $campus->setGroupType($this->filiere);

        self::assertCount(0, $this->validator->validate($campus));
    }

    public function testAGroupUnderAGroupOfItsOwnTypeIsRefused(): void
    {
        $sio1 = new Group('sio-1', 'ROLE_SIO-1');
        $sio1->setGroupType($this->classe);

        $sio2 = new Group('sio-2', 'ROLE_SIO-2');
        $sio2->setGroupType($this->classe);
        $sio2->setParent($sio1);

        $violations = $this->validator->validate($sio2);

        self::assertCount(1, $violations);
        self::assertSame('groupParentSameTypeMessage', $this->firstMessage($violations));
        self::assertSame('parent', $violations[0]->getPropertyPath());
    }

    // Different types, so the rule is satisfied - the type is what separates the levels, not
    // whether one of them has one.
    public function testAnUntypedGroupUnderATypedOnePasses(): void
    {
        $sio = new Group('sio', 'ROLE_SIO');
        $sio->setGroupType($this->filiere);

        $comite = new Group('comite', 'ROLE_COMITE');
        $comite->setParent($sio);

        self::assertCount(0, $this->validator->validate($comite));
    }

    public function testTwoUntypedGroupsCannotBeStacked(): void
    {
        $comite = new Group('comite', 'ROLE_COMITE');
        $sousComite = new Group('sous-comite', 'ROLE_SOUS-COMITE');
        $sousComite->setParent($comite);

        self::assertSame('groupParentBothUntypedMessage', $this->firstMessage($this->validator->validate($sousComite)));
    }

    public function testAGroupCannotBeItsOwnParent(): void
    {
        $sio = new Group('sio', 'ROLE_SIO');
        $sio->setGroupType($this->filiere);
        $sio->setParent($sio);

        self::assertSame('groupParentSelfMessage', $this->firstMessage($this->validator->validate($sio)));
    }

    public function testAGroupCannotBeMovedUnderItsOwnDescendant(): void
    {
        $campus = new Group('campus', 'ROLE_CAMPUS');
        $campus->setGroupType($this->groupType(3, 'Section'));

        $sio = new Group('sio', 'ROLE_SIO');
        $sio->setGroupType($this->filiere);
        $sio->setParent($campus);

        $sio2 = new Group('sio-2', 'ROLE_SIO-2');
        $sio2->setGroupType($this->classe);
        $sio2->setParent($sio);

        // "Campus est dans SIO2" - types differ, so only the loop check can refuse it.
        $campus->setParent($sio2);

        self::assertSame('groupParentCycleMessage', $this->firstMessage($this->validator->validate($campus)));
    }

    public function testAncestorsReadRootFirstAndFeedTheHierarchyPath(): void
    {
        $campus = new Group('campus', 'ROLE_CAMPUS');
        $sio = new Group('sio', 'ROLE_SIO');
        $sio->setParent($campus);
        $sio2 = new Group('sio-2', 'ROLE_SIO-2');
        $sio2->setParent($sio);

        self::assertSame([$campus, $sio], $sio2->getAncestors());
        self::assertSame('campus › sio › sio-2', $sio2->getHierarchyPath());
        self::assertTrue($sio2->isDescendantOf($campus));
        self::assertFalse($campus->isDescendantOf($sio2));
    }

    private function groupType(int $id, string $name): GroupType
    {
        $groupType = new GroupType($name);

        // Ids are what the rule compares, and Doctrine is what normally assigns them.
        $reflection = new \ReflectionProperty(GroupType::class, 'id');
        $reflection->setValue($groupType, $id);

        return $groupType;
    }

    /** @param ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface> $violations */
    private function firstMessage(ConstraintViolationListInterface $violations): string
    {
        self::assertCount(1, $violations);

        return (string) $violations[0]->getMessage();
    }
}
