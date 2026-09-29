<?php

declare(strict_types=1);

namespace App\Tests\Service\Portfolio;

use App\Enum\PortfolioSetting;
use App\Service\Portfolio\PortfolioSectionResolver;
use PHPUnit\Framework\TestCase;

final class PortfolioSectionResolverTest extends TestCase
{
    /** @return list<array{from: \DateTimeImmutable, until: \DateTimeImmutable, cursusYear: int}> */
    private function years(): array
    {
        return [
            ['from' => new \DateTimeImmutable('2025-09-01'), 'until' => new \DateTimeImmutable('2026-06-30'), 'cursusYear' => 1],
            ['from' => new \DateTimeImmutable('2026-09-01'), 'until' => new \DateTimeImmutable('2027-06-30'), 'cursusYear' => 2],
        ];
    }

    public function testTrainingIsAlwaysTheFirstPart(): void
    {
        self::assertSame([1], (new PortfolioSectionResolver())->sections(PortfolioSetting::Training, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-15'), $this->years()));
    }

    public function testWorkplaceFollowsTheCursusYearOfItsDates(): void
    {
        $resolver = new PortfolioSectionResolver();

        self::assertSame([2], $resolver->sections(PortfolioSetting::Workplace, new \DateTimeImmutable('2026-05-19'), new \DateTimeImmutable('2026-06-27'), $this->years()));
        self::assertSame([3], $resolver->sections(PortfolioSetting::Workplace, new \DateTimeImmutable('2027-01-05'), new \DateTimeImmutable('2027-02-15'), $this->years()));
    }

    public function testAnInternshipAcrossBothYearsShowsInBothParts(): void
    {
        self::assertSame([2, 3], (new PortfolioSectionResolver())->sections(PortfolioSetting::Workplace, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-09-20'), $this->years()));
    }

    public function testASummerInternshipGoesToTheYearThatStartedBeforeIt(): void
    {
        self::assertSame([2], (new PortfolioSectionResolver())->sections(PortfolioSetting::Workplace, new \DateTimeImmutable('2026-07-06'), new \DateTimeImmutable('2026-08-14'), $this->years()));
    }

    public function testNoDateNoFormationFallsToTheFirstYear(): void
    {
        self::assertSame([2], (new PortfolioSectionResolver())->sections(PortfolioSetting::Workplace, null, null, $this->years()));
        self::assertSame([2], (new PortfolioSectionResolver())->sections(PortfolioSetting::Workplace, new \DateTimeImmutable('2026-05-19'), null, []));
    }
}
