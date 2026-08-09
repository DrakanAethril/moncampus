<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The "lien envoyé" page pre-fills its own resend button with the address the browser just typed
 * (PublicMagicLoginController hands it over through a read-once flash).
 *
 * Worth a test rather than a browser pass: the field is rendered hidden, so when it silently went
 * empty - the form declared it 'mapped' => false, which makes createForm(..., ['email' => ...])
 * a no-op - nothing on screen changed. The only visible symptom was the resend button quietly
 * doing nothing, which nobody clicks twice.
 */
class MagicLoginResendTest extends FunctionalTestCase
{
    public function testSentPagePreFillsTheResendFormWithTheSubmittedAddress(): void
    {
        $crawler = $this->client->request('GET', '/login/magic-link');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('magic_login_request[submit]')->form([
            'magic_login_request[email]' => 'someone@example.org',
        ]));

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(
            'someone@example.org',
            $crawler->filter('#magic_login_request_email')->attr('value'),
        );
    }

    public function testReachingTheSentPageDirectlyLeavesTheResendFormEmpty(): void
    {
        // No flash to read: the field must render blank rather than carrying over whatever the
        // previous visitor to this browser session asked for.
        $crawler = $this->client->request('GET', '/login/magic-link/sent');

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('#magic_login_request_email')->attr('value'));
    }
}
