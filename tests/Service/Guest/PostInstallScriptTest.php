<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\PostInstallScript;
use PHPUnit\Framework\TestCase;

/**
 * The tokens a post-installation script may use, and what happens to its output.
 *
 * The rule with teeth is the last one: **an unknown token is left exactly as it was**, never
 * replaced by nothing. A script that writes `{{hostnmae}}` into a file has a typo somebody can see
 * and fix; one that writes an empty string has a bug that only shows up as a machine with a blank
 * MOTD three weeks later.
 *
 * The truncation cuts on a line boundary for the same kind of reason: 64 KiB of output ending
 * mid-line reads like a corrupted log, and the thing being cut is usually somebody's apt output.
 */
class PostInstallScriptTest extends TestCase
{
    private function script(): PostInstallScript
    {
        return new PostInstallScript();
    }

    /** @return array<string, string> */
    private function tokens(): array
    {
        return [
            'hostname' => 'srv-web-07',
            'ip' => '10.30.20.57',
            'vmid' => '231',
            'users' => 'marie-dupont jean-martin',
            'batch' => 'SIO2 - TP réseau',
        ];
    }

    public function testEveryTokenIsSubstituted(): void
    {
        $rendered = $this->script()->render(
            "hostnamectl set-hostname {{hostname}}\necho '{{batch}} — {{hostname}} ({{ip}}) #{{vmid}}' > /etc/motd\nfor u in {{users}}; do mkdir -p /var/www/\$u; done",
            $this->tokens(),
        );

        self::assertStringContainsString('hostnamectl set-hostname srv-web-07', $rendered);
        self::assertStringContainsString('SIO2 - TP réseau — srv-web-07 (10.30.20.57) #231', $rendered);
        self::assertStringContainsString('for u in marie-dupont jean-martin', $rendered);
        self::assertStringNotContainsString('{{', $rendered);
    }

    public function testAnUnknownTokenIsLeftAloneRatherThanEmptied(): void
    {
        // The rule that matters: a visible typo beats a silent blank.
        $rendered = $this->script()->render('echo {{hostnmae}} > /etc/motd', $this->tokens());

        self::assertSame('echo {{hostnmae}} > /etc/motd', $rendered);
    }

    public function testAnEmptyUsersListSubstitutesToNothingWithoutBreakingTheLoop(): void
    {
        // A batch with no members is a real state, and `for u in ; do` is a syntax error - so the
        // loop has to survive it, which is the caller's business, but the substitution must at
        // least be predictable.
        $tokens = $this->tokens();
        $tokens['users'] = '';

        self::assertSame('for u in ; do echo $u; done', $this->script()->render('for u in {{users}}; do echo $u; done', $tokens));
    }

    public function testTheSameTokenTwiceIsSubstitutedTwice(): void
    {
        self::assertSame('srv-web-07 srv-web-07', $this->script()->render('{{hostname}} {{hostname}}', $this->tokens()));
    }

    public function testSpacesInsideTheBracesAreAccepted(): void
    {
        // People write them, and refusing would be a rule nobody can see in the field's help text.
        self::assertSame('srv-web-07', $this->script()->render('{{ hostname }}', $this->tokens()));
    }

    public function testAnEmptyScriptRendersToNothing(): void
    {
        self::assertSame('', $this->script()->render('', $this->tokens()));
    }

    public function testEveryPromisedTokenIsActuallySubstituted(): void
    {
        // The help text under the field lists these by name; a token that is advertised and not
        // handled would be left in the script verbatim, which is the one failure mode this class
        // deliberately does not make loud.
        foreach (PostInstallScript::TOKENS as $token) {
            $rendered = $this->script()->render('['.$token.'] {{'.$token.'}}', $this->tokens());

            self::assertStringNotContainsString('{{', $rendered, $token.' is advertised but not substituted');
        }
    }

    // --- truncation -------------------------------------------------------------------------

    public function testShortOutputIsUntouched(): void
    {
        self::assertSame("done\n", $this->script()->truncate("done\n"));
    }

    public function testLongOutputIsCutOnALineBoundary(): void
    {
        $line = str_repeat('a', 200)."\n";
        $long = str_repeat($line, 500); // 100 500 bytes

        $truncated = $this->script()->truncate($long);

        self::assertLessThanOrEqual(PostInstallScript::MAX_OUTPUT_BYTES + 64, \strlen($truncated));
        self::assertStringEndsWith("…\n", $truncated);
        // Cut between lines, not through one: the middle of somebody's apt output reads as
        // corruption rather than as truncation. Everything left, once the marker is removed, has
        // to be whole copies of the repeated line - terminator included.
        $body = str_replace("…\n", '', $truncated);
        self::assertSame('', str_replace($line, '', $body), 'only whole lines survive');
    }

    public function testOutputWithNoLineBreakAtAllIsStillCut(): void
    {
        $blob = str_repeat('x', PostInstallScript::MAX_OUTPUT_BYTES * 2);
        $truncated = $this->script()->truncate($blob);

        self::assertLessThanOrEqual(PostInstallScript::MAX_OUTPUT_BYTES + 64, \strlen($truncated));
    }

    public function testExactlyTheCeilingIsNotTruncated(): void
    {
        $exact = str_repeat('y', PostInstallScript::MAX_OUTPUT_BYTES);

        self::assertSame($exact, $this->script()->truncate($exact));
    }

    // --- the guards -------------------------------------------------------------------------

    public function testTheScriptIsWrappedSoItStopsAtTheFirstFailure(): void
    {
        $wrapped = $this->script()->wrap("apt-get update\napt-get install -y nginx");

        self::assertStringContainsString('set -e', $wrapped);
        // The two ways an unattended script hangs instead of failing.
        self::assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $wrapped);
        self::assertStringContainsString('exec </dev/null', $wrapped);
    }

    public function testAnExistingShebangIsNotDuplicated(): void
    {
        $wrapped = $this->script()->wrap("#!/bin/bash\nset -e\napt-get update");

        self::assertSame(1, substr_count($wrapped, '#!/bin/bash'));
    }
}
