<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxmox;

use App\Service\Proxmox\ProxmoxFailureMessage;
use App\Service\Proxmox\ProxmoxUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * Which of a failure's two messages reaches an administrator.
 *
 * Worth its own tests because the answer is a fallback chain rather than a branch, and because the
 * string it returns is not only shown: App\Service\Proxmox\ProxmoxHostChecker stores it on the host,
 * and the hosts list prints it raw. A wrong answer here is a translation key rendered on screen.
 */
class ProxmoxFailureMessageTest extends TestCase
{
    private function message(): ProxmoxFailureMessage
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'proxmoxHostUnreachableError' => 'Hôte injoignable à l’adresse %address%.',
            'proxmoxRefusalBusy' => 'Une autre opération est en cours.',
        ], 'fr');

        return new ProxmoxFailureMessage($translator);
    }

    public function testAFailureThatNamesAKeyIsTranslatedWithItsParameters(): void
    {
        $exception = new ProxmoxUnavailableException(
            'GET /version failed: Idle timeout reached',
            userMessageKey: 'proxmoxHostUnreachableError',
            userMessageParameters: ['%address%' => 'https://192.0.2.10:8006'],
        );

        self::assertSame('Hôte injoignable à l’adresse https://192.0.2.10:8006.', $this->message()->readable($exception));
    }

    public function testAFailureThrownAsABareKeyIsTranslatedToo(): void
    {
        // Most refusals of this area are thrown this way, message and key in one. They predate the
        // second message and must keep working untouched.
        self::assertSame('Une autre opération est en cours.', $this->message()->readable(new ProxmoxUnavailableException('proxmoxRefusalBusy')));
    }

    public function testAMessageThatIsNeitherSurvivesUnchanged(): void
    {
        // trans() answers an unknown key with the key itself, which is what makes the fallback safe:
        // a technical sentence with no translation is shown as it is rather than as an empty line.
        $said = 'POST /nodes/pve/qemu answered HTTP 500';

        self::assertSame($said, $this->message()->readable(new ProxmoxUnavailableException($said)));
    }
}
