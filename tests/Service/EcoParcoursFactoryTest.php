<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\User;
use App\Repository\EcoCheckpointRepository;
use App\Service\EcoParcoursFactory;
use App\Service\EcoRandomCode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A checkpoint's short code validates the flag when typed by hand, so knowing one must teach
 * nothing about the others. They used to be spelt « PVT-DEP », « PVT-B01 », « PVT-B02 »… - one
 * look at the start flag gave the whole parcours away.
 */
class EcoParcoursFactoryTest extends TestCase
{
    public function testEveryCodeIsDrawnAtRandomFromTheUnambiguousAlphabet(): void
    {
        $codes = $this->codesOf($this->factory()->create(new User('eco.teacher'), 'Parc Victor-Thuillat', 12)->getCheckpoints()->toArray());

        self::assertCount(14, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^['.EcoRandomCode::ALPHABET.']{'.EcoParcoursFactory::SHORT_CODE_LENGTH.'}$/', $code);
            self::assertStringNotContainsString('PVT', $code);
        }
    }

    public function testNoTwoCheckpointsOfOneParcoursShareACodeEvenBeforeAnythingIsFlushed(): void
    {
        // The repository sees nothing of the parcours being built: uniqueness within it cannot
        // rest on the database.
        $codes = $this->codesOf($this->factory()->create(new User('eco.teacher'), 'Bastide', 40)->getCheckpoints()->toArray());

        self::assertSame($codes, array_values(array_unique($codes)));
    }

    public function testACodeAlreadyTakenElsewhereIsDrawnAgain(): void
    {
        $taken = [];
        $repository = self::createStub(EcoCheckpointRepository::class);
        // The first code drawn for the start flag is "taken"; every later one is free.
        $repository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$taken): ?EcoCheckpoint {
            $taken[] = $criteria['shortCode'];

            return 1 === \count($taken) ? self::createStub(EcoCheckpoint::class) : null;
        });

        $parcours = (new EcoParcoursFactory(self::createStub(EntityManagerInterface::class), $repository))->create(new User('eco.teacher'), 'Bastide', 1);

        $startCode = $parcours->getCheckpoints()->first()->getShortCode();
        self::assertNotSame($taken[0], $startCode);
        self::assertSame($taken[1], $startCode);
    }

    private function factory(): EcoParcoursFactory
    {
        $repository = self::createStub(EcoCheckpointRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        return new EcoParcoursFactory(self::createStub(EntityManagerInterface::class), $repository);
    }

    /**
     * @param list<EcoCheckpoint> $checkpoints
     *
     * @return list<string>
     */
    private function codesOf(array $checkpoints): array
    {
        return array_map(static fn (EcoCheckpoint $checkpoint): string => (string) $checkpoint->getShortCode(), $checkpoints);
    }
}
