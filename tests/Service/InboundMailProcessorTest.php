<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\EmailMessage;
use App\Enum\InboundMailOutcome;
use App\Repository\EmailAliasRepository;
use App\Repository\EmailMessageRepository;
use App\Service\InboundMailProcessor;
use App\Service\SchoolMailApplicationRecovery;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What processing one object of `incoming/` answers.
 *
 * The answer used to be a bare « true » whether a row had been written or not, and the nightly
 * reconciliation took every « true » for a mail it had just recovered: an object whose Message-ID
 * was already in the database - a student copying their own school address on a send - was
 * « replayed » every night for as long as it stayed in the scanned window, and rang Discord each
 * time for a mail nobody had lost.
 */
class InboundMailProcessorTest extends TestCase
{
    private const string KEY = 'incoming/abc123';

    public function testAnObjectAlreadyFiledIsNotReadAgain(): void
    {
        $messages = $this->createMock(EmailMessageRepository::class);
        $messages->expects(self::once())->method('findOneBySourceKey')->with(self::KEY)->willReturn(new EmailMessage());

        $s3 = $this->createMock(S3Client::class);
        $s3->expects(self::never())->method('__call');

        self::assertSame(InboundMailOutcome::AlreadyStored, $this->processor($s3, $messages, persists: false)->process(self::KEY));
    }

    public function testAnObjectCarryingAKnownMessageIdWritesNothingAndSaysSo(): void
    {
        $messages = $this->createMock(EmailMessageRepository::class);
        $messages->method('findOneBySourceKey')->willReturn(null);
        $messages->expects(self::once())->method('findOneByMessageId')->with('<known@example.org>')->willReturn(new EmailMessage());

        $s3 = $this->s3(['getObject']);

        self::assertSame(InboundMailOutcome::DuplicateMessageId, $this->processor($s3, $messages, persists: false)->process(self::KEY));
    }

    public function testANewMailIsFiledAndStored(): void
    {
        $messages = $this->createStub(EmailMessageRepository::class);
        $messages->method('findOneBySourceKey')->willReturn(null);
        $messages->method('findOneByMessageId')->willReturn(null);

        $s3 = $this->s3(['getObject', 'copyObject']);

        self::assertSame(InboundMailOutcome::Stored, $this->processor($s3, $messages, persists: true)->process(self::KEY));
    }

    /**
     * The mail client reaches S3 through `__call`: the operations are named here so that a test
     * also says which ones its case is allowed to make.
     *
     * @param list<string> $allowed
     */
    private function s3(array $allowed): S3Client
    {
        $s3 = $this->createStub(S3Client::class);
        $s3->method('__call')->willReturnCallback(static function (string $operation) use ($allowed): array {
            self::assertContains($operation, $allowed);

            return 'getObject' === $operation ? ['Body' => self::eml()] : [];
        });

        return $s3;
    }

    private function processor(S3Client $s3, EmailMessageRepository $messages, bool $persists): InboundMailProcessor
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($persists ? self::once() : self::never())->method('persist');
        $entityManager->expects($persists ? self::once() : self::never())->method('flush');

        return new InboundMailProcessor(
            $s3,
            $this->createStub(EmailAliasRepository::class),
            $messages,
            $this->createStub(SchoolMailApplicationRecovery::class),
            $entityManager,
            new NullLogger(),
            'mail-bucket',
            'etu.example.org',
        );
    }

    private static function eml(): string
    {
        return implode("\r\n", [
            'From: Recruteur <rh@example.org>',
            'To: jdupont@etu.example.org',
            'Subject: Votre candidature',
            'Message-ID: <known@example.org>',
            'Date: Thu, 08 Oct 2026 10:00:00 +0200',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Bonjour.',
            '',
        ]);
    }
}
