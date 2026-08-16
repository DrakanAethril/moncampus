<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AntivirusScanner;
use App\Service\ClamAvClient;
use App\Service\ClamAvReply;
use App\Service\ClamAvUnavailableException;
use App\Service\InfectedUploadException;
use PHPUnit\Framework\TestCase;

/**
 * The five decisions this scanner encodes, each of which is a way to get it wrong:
 *
 * - **unconfigured means disabled**, and that is a *different state* from unreachable. Dev and CI
 *   run unconfigured; production runs configured.
 * - **fail closed, not open.** Configured but unreachable refuses the upload. An antivirus that
 *   silently lets files through when it is down is worse than none, because it is believed.
 * - an infected file is refused by name, so the message can say what was found;
 * - the scan happens on the temp file, **before** a byte reaches S3, so a rejected file never
 *   enters the bucket and nothing has to be cleaned up afterwards;
 * - the same file is scanned once per request even though two layers ask - the form constraint for
 *   the message, the write path as the guarantee.
 */
class AntivirusScannerTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testUnconfiguredMeansDisabledAndNeverCallsTheEngine(): void
    {
        $client = $this->createMock(ClamAvClient::class);
        $client->expects(self::never())->method('scan');

        $scanner = new AntivirusScanner($client, '');

        self::assertFalse($scanner->isEnabled());
        $scanner->assertClean($this->file('anything'), 'notes.txt');
    }

    public function testACleanFilePasses(): void
    {
        $scanner = new AntivirusScanner($this->clientAnswering(ClamAvReply::parse("stream: OK\0")), 'clamav://clamav:3310');

        self::assertTrue($scanner->isEnabled());
        $scanner->assertClean($this->file('harmless'), 'notes.txt');
    }

    public function testAnInfectedFileIsRefusedByName(): void
    {
        $scanner = new AntivirusScanner($this->clientAnswering(ClamAvReply::parse("stream: Win.Test.EICAR_HDB-1 FOUND\0")), 'clamav://clamav:3310');

        $this->expectException(InfectedUploadException::class);
        $this->expectExceptionMessageMatches('/EICAR/');

        $scanner->assertClean($this->file('x5O!P%@AP'), 'eicar.txt');
    }

    public function testAnUnreachableEngineRefusesTheUploadRatherThanLettingItThrough(): void
    {
        $client = $this->createStub(ClamAvClient::class);
        $client->method('scan')->willThrowException(new ClamAvUnavailableException('connection refused'));

        $scanner = new AntivirusScanner($client, 'clamav://clamav:3310');

        $this->expectException(ClamAvUnavailableException::class);

        $scanner->assertClean($this->file('harmless'), 'notes.txt');
    }

    public function testAnEngineErrorReplyAlsoRefusesTheUpload(): void
    {
        // Nothing was scanned, so "clean" is not an answer anybody may give.
        $scanner = new AntivirusScanner($this->clientAnswering(ClamAvReply::parse('INSTREAM size limit exceeded. ERROR')), 'clamav://clamav:3310');

        $this->expectException(ClamAvUnavailableException::class);

        $scanner->assertClean($this->file('harmless'), 'notes.txt');
    }

    public function testTheSameFileIsScannedOncePerRequestEvenThoughTwoLayersAsk(): void
    {
        // The form constraint scans it for the message, then the write path scans it as the
        // guarantee - one clamd round trip, not two.
        $client = $this->createMock(ClamAvClient::class);
        $client->expects(self::once())->method('scan')->willReturn(ClamAvReply::parse("stream: OK\0"));

        $scanner = new AntivirusScanner($client, 'clamav://clamav:3310');
        $path = $this->file('harmless');

        $scanner->assertClean($path, 'notes.txt');
        $scanner->assertClean($path, 'notes.txt');
    }

    public function testTwoDifferentFilesAreBothScanned(): void
    {
        $client = $this->createMock(ClamAvClient::class);
        $client->expects(self::exactly(2))->method('scan')->willReturn(ClamAvReply::parse("stream: OK\0"));

        $scanner = new AntivirusScanner($client, 'clamav://clamav:3310');

        $scanner->assertClean($this->file('one'), 'a.txt');
        $scanner->assertClean($this->file('two'), 'b.txt');
    }

    public function testAnUnreadableFileIsRefusedRatherThanSkipped(): void
    {
        $scanner = new AntivirusScanner($this->clientAnswering(ClamAvReply::parse("stream: OK\0")), 'clamav://clamav:3310');

        $this->expectException(ClamAvUnavailableException::class);

        $scanner->assertClean('/nonexistent/path/notes.txt', 'notes.txt');
    }

    private function clientAnswering(ClamAvReply $reply): ClamAvClient
    {
        $client = $this->createStub(ClamAvClient::class);
        $client->method('scan')->willReturn($reply);

        return $client;
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'av');

        if (false === $path) {
            self::fail('Could not create a temporary file.');
        }

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
