<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\EmailDirection;
use App\Enum\SchoolMailApplicationEvidence;
use App\Repository\EmailMessageRepository;
use App\Service\HtmlPlainText;
use App\Service\SchoolMailApplicationRecovery;
use PHPUnit\Framework\TestCase;

/**
 * What a delivery failure notice is allowed to decide.
 *
 * This is the one place where a mail is filed under a démarche nobody named, so the cases that
 * matter are the ones where it must *refuse*: two démarches written to the same address, an address
 * the student never wrote to, the student's own alias quoted back at them.
 */
class SchoolMailApplicationRecoveryTest extends TestCase
{
    private const string DOMAIN = 'devetu.beaupeyrat.org';
    private const string SES_ID = '0113019fd06b7c6d-5ddd5424-453b-4269-b9da-cca2f35f16b9-000000';

    public function testTheQuotedMessageIdFilesTheNotice(): void
    {
        $application = $this->application('Néopixel');
        $send = $this->send($application, ['rh@neopixel.fr']);

        $notice = $this->notice(<<<TEXT
            Your message could not be delivered.

            ----- Original message -----
            Message-ID: <0113019fd06b7c6d-5ddd5424-453b-4269-b9da-cca2f35f16b9-000000@eu-west-3.amazonses.com>
            To: rh@neopixel.fr
            TEXT);

        $match = $this->recovery([$send])->recover($notice);

        self::assertNotNull($match);
        self::assertSame($application, $match->application);
        self::assertSame(SchoolMailApplicationEvidence::MessageId, $match->kind);
    }

    public function testTheBareSesIdentifierIsTheSameSend(): void
    {
        // SES rewrites the Message-ID header, so a notice can quote either form - the same send
        // answers to both, exactly as EmailMessageRepository::findOneByAnyMessageId has it.
        $application = $this->application('Néopixel');
        $send = $this->send($application, ['rh@neopixel.fr']);

        $notice = $this->notice('Message-ID: <'.self::SES_ID.'@some-relay.example.com>');

        $match = $this->recovery([$send])->recover($notice);

        self::assertNotNull($match);
        self::assertSame($application, $match->application);
    }

    public function testTheFailingAddressFilesTheNotice(): void
    {
        $application = $this->application('Néopixel');
        $send = $this->send($application, ['rh@neopixel.fr']);

        $notice = $this->notice("Delivery has failed to these recipients:\n\nrh@neopixel.fr\nThe mailbox is unavailable.");

        $match = $this->recovery([$send])->recover($notice);

        self::assertNotNull($match);
        self::assertSame($application, $match->application);
        self::assertSame(SchoolMailApplicationEvidence::Address, $match->kind);
        self::assertSame('rh@neopixel.fr', $match->evidence);
    }

    public function testAnAddressWrittenToUnderTwoDemarchesDecidesNothing(): void
    {
        // The whole point of the rule: the notice is about one of them, and nothing in it says
        // which. Leaving the mail outside every démarche is the honest answer.
        $first = $this->send($this->application('Néopixel'), ['contact@groupe.fr']);
        $second = $this->send($this->application('Groupe - agence Lyon'), ['contact@groupe.fr']);

        $notice = $this->notice('Delivery has failed to contact@groupe.fr.');

        self::assertNull($this->recovery([$first, $second])->recover($notice));
    }

    public function testTheStudentsOwnAliasIsNeverTheEvidence(): void
    {
        // A notice quotes the sender as loudly as the recipient, and the alias is on every send.
        $send = $this->send($this->application('Néopixel'), ['camille.roux@'.self::DOMAIN, 'rh@neopixel.fr']);

        $notice = $this->notice('Your message from camille.roux@'.self::DOMAIN.' was rejected.');

        self::assertNull($this->recovery([$send])->recover($notice));
    }

    public function testAnAddressNobodyWroteToDecidesNothing(): void
    {
        $send = $this->send($this->application('Néopixel'), ['rh@neopixel.fr']);

        $notice = $this->notice('Delivery has failed to postmaster@ailleurs.fr.');

        self::assertNull($this->recovery([$send])->recover($notice));
    }

    public function testTheAddressIsReadInsideAnHtmlNotice(): void
    {
        $application = $this->application('Néopixel');
        $send = $this->send($application, ['rh@neopixel.fr']);

        $notice = $this->notice(null, '<p>Échec de la remise à <a href="mailto:rh@neopixel.fr">rh@neopixel.fr</a>.</p>');

        $match = $this->recovery([$send])->recover($notice);

        self::assertNotNull($match);
        self::assertSame($application, $match->application);
    }

    public function testAMailAlreadyFiledIsLeftAlone(): void
    {
        $send = $this->send($this->application('Néopixel'), ['rh@neopixel.fr']);
        $notice = $this->notice('Delivery has failed to rh@neopixel.fr.');
        $notice->setJobApplication($this->application('Autre chose'));

        self::assertNull($this->recovery([$send])->recover($notice));
    }

    public function testASendIsNeverReadAsAnIncomingMail(): void
    {
        $send = $this->send($this->application('Néopixel'), ['rh@neopixel.fr']);
        $outbound = $this->notice('rh@neopixel.fr')->setDirection(EmailDirection::Outbound);

        self::assertNull($this->recovery([$send])->recover($outbound));
    }

    /** @param list<EmailMessage> $sends */
    private function recovery(array $sends): SchoolMailApplicationRecovery
    {
        $messages = $this->createStub(EmailMessageRepository::class);
        $messages->method('findSendsWithApplicationForStudent')->willReturn($sends);

        return new SchoolMailApplicationRecovery($messages, new HtmlPlainText(), self::DOMAIN);
    }

    private function notice(?string $text, ?string $html = null): EmailMessage
    {
        return (new EmailMessage())
            ->setDirection(EmailDirection::Inbound)
            ->setStudent($this->student())
            ->setFromAddress('MAILER-DAEMON@neopixel.fr')
            ->setSubject('Delivery Status Notification (Failure)')
            ->setToAddresses(['camille.roux@'.self::DOMAIN])
            ->setTextBody($text)
            ->setHtmlBody($html)
            ->setS3Key('applications/croux/mails/ndr.eml');
    }

    /** @param list<string> $recipients */
    private function send(JobApplication $application, array $recipients): EmailMessage
    {
        return (new EmailMessage())
            ->setDirection(EmailDirection::Outbound)
            ->setStudent($this->student())
            ->setMessageId('<'.self::SES_ID.'@eu-west-3.amazonses.com>')
            ->setProviderMessageId(self::SES_ID)
            ->setFromAddress('camille.roux@'.self::DOMAIN)
            ->setToAddresses($recipients)
            ->setS3Key('candidatures/croux/mails/abc.eml')
            ->setJobApplication($application);
    }

    private function application(string $name): JobApplication
    {
        return (new JobApplication())->setName($name);
    }

    private function student(): User
    {
        return new User('croux');
    }
}
