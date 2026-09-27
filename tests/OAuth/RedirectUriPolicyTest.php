<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\OAuth\RedirectUriPolicy;
use PHPUnit\Framework\TestCase;

class RedirectUriPolicyTest extends TestCase
{
    private RedirectUriPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RedirectUriPolicy();
    }

    public function testAcceptsClaudesCallbacksAndTheLoopback(): void
    {
        self::assertTrue($this->policy->isRegistrable('https://claude.ai/api/mcp/auth_callback'));
        self::assertTrue($this->policy->isRegistrable('https://claude.com/api/mcp/auth_callback'));
        self::assertTrue($this->policy->isRegistrable('http://localhost:53682/callback'));
        self::assertTrue($this->policy->isRegistrable('http://127.0.0.1/callback'));
        self::assertTrue($this->policy->isRegistrable('http://localhost:6274/oauth/callback'));
    }

    public function testRefusesEverythingElse(): void
    {
        self::assertFalse($this->policy->isRegistrable('https://evil.example/api/mcp/auth_callback'));
        self::assertFalse($this->policy->isRegistrable('https://claude.ai/api/mcp/auth_callback/../elsewhere'));
        self::assertFalse($this->policy->isRegistrable('https://localhost/callback'));
        self::assertFalse($this->policy->isRegistrable('http://localhost.evil.example/callback'));
        self::assertFalse($this->policy->isRegistrable('http://user@localhost/callback'));
        self::assertFalse($this->policy->isRegistrable('http://localhost/callback#fragment'));
        self::assertFalse($this->policy->isRegistrable('not a url'));
    }

    public function testAHostedCallbackMustMatchExactly(): void
    {
        $registered = ['https://claude.ai/api/mcp/auth_callback'];

        self::assertTrue($this->policy->matches($registered, 'https://claude.ai/api/mcp/auth_callback'));
        self::assertFalse($this->policy->matches($registered, 'https://claude.ai/api/mcp/auth_callback?x=1'));
        self::assertFalse($this->policy->matches($registered, 'https://claude.com/api/mcp/auth_callback'));
    }

    public function testALoopbackCallbackMayChangeItsPortOnly(): void
    {
        $registered = ['http://localhost:1000/callback'];

        self::assertTrue($this->policy->matches($registered, 'http://localhost:53682/callback'));
        self::assertTrue($this->policy->matches($registered, 'http://localhost/callback'));
        self::assertFalse($this->policy->matches($registered, 'http://localhost:53682/other'));
        self::assertFalse($this->policy->matches($registered, 'http://127.0.0.1:53682/callback'));
    }
}
