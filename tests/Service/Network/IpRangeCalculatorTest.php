<?php

declare(strict_types=1);

namespace App\Tests\Service\Network;

use App\Service\Network\IpRangeCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic under every address the console hands out. Pure, and therefore the first file of
 * the whole feature: nothing else can be trusted until this is.
 *
 * Three families are pinned, and each has a way of going quietly wrong:
 *
 *   - **bounds**: a /24 has 254 usable addresses and not 256, but a /31 has *two* and a /32 has
 *     *one* - RFC 3021 removed the network/broadcast pair for point-to-point links, and code that
 *     subtracts two unconditionally answers zero or minus one for those.
 *   - **the next free one**: the answer must respect the declared usable bounds rather than the
 *     CIDR, because those bounds are the whole point of the field - without them the console would
 *     eventually hand out the gateway.
 *   - **overlap**: two ranges that intersect produce address conflicts weeks later, blamed on
 *     anything but the declaration that caused them.
 */
class IpRangeCalculatorTest extends TestCase
{
    private function calculator(): IpRangeCalculator
    {
        return new IpRangeCalculator();
    }

    // --- parsing ----------------------------------------------------------------------------

    /** @return iterable<string, array{string, bool}> */
    public static function cidrProvider(): iterable
    {
        yield 'a plain /24' => ['10.30.20.0/24', true];
        yield 'a /16' => ['172.16.0.0/16', true];
        yield 'a /31, which is legal' => ['192.0.2.0/31', true];
        yield 'a /32, which is a single host' => ['192.0.2.7/32', true];
        yield 'a /0' => ['0.0.0.0/0', true];
        yield 'no prefix at all' => ['10.30.20.0', false];
        yield 'a prefix out of range' => ['10.30.20.0/33', false];
        yield 'a negative prefix' => ['10.30.20.0/-1', false];
        yield 'an octet out of range' => ['10.30.300.0/24', false];
        yield 'not an address' => ['not-an-address/24', false];
        yield 'empty' => ['', false];
        // IPv6 is out of scope and must be refused rather than half-handled: cloud-init's
        // ipconfig0, the scanner and the whole registry are written for v4.
        yield 'IPv6, deliberately unsupported' => ['2001:db8::/64', false];
    }

    #[DataProvider('cidrProvider')]
    public function testCidrValidity(string $cidr, bool $valid): void
    {
        self::assertSame($valid, $this->calculator()->isValidCidr($cidr));
    }

    public function testAnAddressIsValidatedOnItsOwn(): void
    {
        $calculator = $this->calculator();

        self::assertTrue($calculator->isValidAddress('10.30.20.1'));
        self::assertFalse($calculator->isValidAddress('10.30.20.256'));
        self::assertFalse($calculator->isValidAddress('2001:db8::1'));
        self::assertFalse($calculator->isValidAddress(''));
    }

    // --- bounds -----------------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string, int}> */
    public static function boundsProvider(): iterable
    {
        // cidr, first usable, last usable, count
        yield '/24 drops network and broadcast' => ['10.30.20.0/24', '10.30.20.1', '10.30.20.254', 254];
        yield '/25' => ['10.30.20.128/25', '10.30.20.129', '10.30.20.254', 126];
        yield '/30 leaves two' => ['192.0.2.0/30', '192.0.2.1', '192.0.2.2', 2];
        // RFC 3021: a /31 is a point-to-point link and both addresses are usable.
        yield '/31 leaves two, not zero' => ['192.0.2.0/31', '192.0.2.0', '192.0.2.1', 2];
        yield '/32 leaves one, not minus one' => ['192.0.2.7/32', '192.0.2.7', '192.0.2.7', 1];
        yield 'a host address is normalised to its network' => ['10.30.20.57/24', '10.30.20.1', '10.30.20.254', 254];
    }

    #[DataProvider('boundsProvider')]
    public function testDefaultUsableBounds(string $cidr, string $first, string $last, int $count): void
    {
        $calculator = $this->calculator();

        self::assertSame([$first, $last], $calculator->defaultUsableBounds($cidr));
        self::assertSame($count, $calculator->capacity($first, $last));
    }

    public function testTheNetworkAddressIsDerivedFromAnyAddressInIt(): void
    {
        self::assertSame('10.30.20.0', $this->calculator()->networkAddress('10.30.20.57/24'));
        self::assertSame('10.30.20.128', $this->calculator()->networkAddress('10.30.20.200/25'));
    }

    public function testContainment(): void
    {
        $calculator = $this->calculator();

        self::assertTrue($calculator->contains('10.30.20.0/24', '10.30.20.1'));
        self::assertTrue($calculator->contains('10.30.20.0/24', '10.30.20.255'), 'the broadcast address is still in the network');
        self::assertFalse($calculator->contains('10.30.20.0/24', '10.30.21.1'));
        self::assertFalse($calculator->contains('10.30.20.0/24', 'nonsense'));
    }

    // --- capacity and the next free address -------------------------------------------------

    public function testCapacityCountsBothBounds(): void
    {
        $calculator = $this->calculator();

        self::assertSame(200, $calculator->capacity('10.30.20.50', '10.30.20.249'));
        self::assertSame(1, $calculator->capacity('10.30.20.50', '10.30.20.50'));
        self::assertSame(0, $calculator->capacity('10.30.20.60', '10.30.20.50'), 'reversed bounds hold nothing');
    }

    public function testTheNextFreeAddressIsTheFirstOneNobodyHolds(): void
    {
        self::assertSame(
            '10.30.20.50',
            $this->calculator()->nextFree('10.30.20.50', '10.30.20.249', []),
        );
    }

    public function testTakenAddressesAreSkipped(): void
    {
        self::assertSame(
            '10.30.20.53',
            $this->calculator()->nextFree('10.30.20.50', '10.30.20.249', ['10.30.20.50', '10.30.20.51', '10.30.20.52']),
        );
    }

    public function testTakenAddressesOutsideTheBoundsAreIrrelevant(): void
    {
        self::assertSame(
            '10.30.20.50',
            $this->calculator()->nextFree('10.30.20.50', '10.30.20.249', ['10.30.20.1', '10.30.20.250']),
        );
    }

    public function testAFullRangeAnswersNothingRatherThanTheLastAddressAgain(): void
    {
        $taken = [];
        for ($last = 50; $last <= 52; ++$last) {
            $taken[] = '10.30.20.'.$last;
        }

        self::assertNull($this->calculator()->nextFree('10.30.20.50', '10.30.20.52', $taken));
    }

    public function testASingleAddressRangeWorksBothWays(): void
    {
        $calculator = $this->calculator();

        self::assertSame('10.30.20.50', $calculator->nextFree('10.30.20.50', '10.30.20.50', []));
        self::assertNull($calculator->nextFree('10.30.20.50', '10.30.20.50', ['10.30.20.50']));
    }

    public function testTheBoundsAreHonouredRatherThanTheWholeNetwork(): void
    {
        // The point of the usable bounds: without them the console would hand out the gateway.
        // .1 to .49 are the infrastructure's, and no amount of free space changes that.
        $calculator = $this->calculator();
        $next = $calculator->nextFree('10.30.20.50', '10.30.20.249', []);

        self::assertNotSame('10.30.20.1', $next);
        self::assertSame('10.30.20.50', $next);
    }

    /** @return iterable<string, array{list<string>, int}> */
    public static function freeCountProvider(): iterable
    {
        yield 'nothing taken' => [[], 200];
        yield 'three taken' => [['10.30.20.50', '10.30.20.51', '10.30.20.99'], 197];
        yield 'a duplicate counts once' => [['10.30.20.50', '10.30.20.50'], 199];
        yield 'an address outside the bounds does not count' => [['10.30.20.1'], 200];
    }

    /** @param list<string> $taken */
    #[DataProvider('freeCountProvider')]
    public function testFreeCount(array $taken, int $expected): void
    {
        self::assertSame($expected, $this->calculator()->freeCount('10.30.20.50', '10.30.20.249', $taken));
    }

    // --- overlap ----------------------------------------------------------------------------

    /** @return iterable<string, array{string, string, bool}> */
    public static function overlapProvider(): iterable
    {
        yield 'identical' => ['10.30.20.0/24', '10.30.20.0/24', true];
        yield 'one inside the other' => ['10.30.0.0/16', '10.30.20.0/24', true];
        yield 'the other way round' => ['10.30.20.0/24', '10.30.0.0/16', true];
        yield 'adjacent but disjoint' => ['10.30.20.0/24', '10.30.21.0/24', false];
        yield 'far apart' => ['10.30.20.0/24', '192.168.1.0/24', false];
        yield 'halves of one network' => ['10.30.20.0/25', '10.30.20.128/25', false];
        yield 'a /32 inside a /24' => ['10.30.20.57/32', '10.30.20.0/24', true];
    }

    #[DataProvider('overlapProvider')]
    public function testOverlap(string $a, string $b, bool $expected): void
    {
        self::assertSame($expected, $this->calculator()->overlaps($a, $b));
    }

    public function testAnUnparseableCidrOverlapsNothing(): void
    {
        // A malformed declaration must not silently claim to collide with everything, which would
        // block every save on a screen whose validation has already rejected it for its own reason.
        self::assertFalse($this->calculator()->overlaps('nonsense', '10.30.20.0/24'));
    }

    // --- sorting ----------------------------------------------------------------------------

    public function testAddressesSortNumericallyRatherThanAlphabetically(): void
    {
        // The trap a plain sort() falls into: "10.30.20.9" comes after "10.30.20.10" as a string,
        // and a registry listed that way looks shuffled.
        $sorted = $this->calculator()->sortAddresses(['10.30.20.10', '10.30.20.9', '10.30.20.100', '10.30.20.1']);

        self::assertSame(['10.30.20.1', '10.30.20.9', '10.30.20.10', '10.30.20.100'], $sorted);
    }
}
