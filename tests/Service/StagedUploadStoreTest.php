<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AntivirusScanner;
use App\Service\ClamAvClient;
use App\Service\ObjectStore;
use App\Service\StagedUploadStore;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The staging area, over a real filesystem rather than a mock: what this object does is move bytes
 * between two keys, and a doubled mock would only assert that it called what it calls.
 *
 * The antivirus is left unconfigured (a blank DSN), which is the "scanning disabled" state
 * App\Service\AntivirusScanner is explicit about - the scan itself has its own tests, and what is
 * under test here is the token and the two objects it names.
 */
class StagedUploadStoreTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;
    private StagedUploadStore $store;

    /** @var list<string> the origins scheduled for deletion, in order */
    private array $scheduled = [];

    /** @var list<string> the keys whose scheduled deletion was cancelled again */
    private array $cancelled = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/staged-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);

        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));

        // Removal goes through the choke point, never through Flysystem
        // (design/validated/object-deletion.md) - so the double both stands in for S3 and records
        // what this store asked somebody else to do. `storageKeyFor()` is the identity here: the
        // test filesystem has no environment prefix.
        $objectStore = $this->createStub(ObjectStore::class);
        $objectStore->method('storageKeyFor')->willReturnArgument(0);
        $objectStore->method('remove')->willReturnCallback(function (string $key): void {
            $this->filesystem->delete($key);
        });
        $objectStore->method('scheduleDeletion')->willReturnCallback(function (string $key, ?string $origin): void {
            $this->scheduled[] = $origin ?? '(derived)';
        });
        $objectStore->method('cancelDeletion')->willReturnCallback(function (string $key): void {
            $this->cancelled[] = $key;
        });

        $this->store = new StagedUploadStore($this->filesystem, new AntivirusScanner(new ClamAvClient(), ''), $objectStore, 'test-secret');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testStagingWritesUnderTheStagedPrefixAndDescribesTheFile(): void
    {
        $staged = $this->store->stage($this->upload('cours.pdf', 'body'), 12);

        self::assertStringStartsWith(StagedUploadStore::PREFIX.'12/', $staged->key);
        self::assertStringEndsWith('.pdf', $staged->key);
        self::assertSame('cours.pdf', $staged->originalName);
        self::assertSame(4, $staged->size);
        self::assertTrue($this->filesystem->fileExists($staged->key));
    }

    public function testATokenResolvesBackForItsOwnerAndForNobodyElse(): void
    {
        $staged = $this->store->stage($this->upload('cours.pdf', 'body'), 12);

        $resolved = $this->store->resolve($staged->token, 12);
        self::assertNotNull($resolved);
        self::assertSame($staged->key, $resolved->key);
        self::assertSame('cours.pdf', $resolved->originalName);

        // The signature says "we wrote this token"; the owner says "it is yours". A second account
        // holding a stolen token still claims nothing.
        self::assertNull($this->store->resolve($staged->token, 13));
    }

    public function testATamperedOrMalformedTokenResolvesToNothing(): void
    {
        $staged = $this->store->stage($this->upload('cours.pdf', 'body'), 12);
        [$payload, $signature] = explode('.', $staged->token);

        self::assertNull($this->store->resolve($payload.'.'.strrev($signature), 12));
        self::assertNull($this->store->resolve('not-a-token', 12));
        self::assertNull($this->store->resolve(rtrim(strtr(base64_encode('{"k":"staged/12/x.pdf","n":"x.pdf","m":"application/pdf","s":4,"o":12}'), '+/', '-_'), '=').'.forged', 12));
    }

    public function testClaimingMovesTheObjectIntoTheCallersPrefix(): void
    {
        $staged = $this->store->stage($this->upload('cours.pdf', 'body'), 12);

        $key = $this->store->claim($staged, 'library/', 'abc.pdf');

        self::assertSame('library/abc.pdf', $key);
        self::assertSame('body', $this->filesystem->read($key));
        // The staged copy goes at once rather than waiting for the purge: the bytes were taken over,
        // not deleted, and leaving them would charge the platform twice for one file.
        self::assertFalse($this->filesystem->fileExists($staged->key));
    }

    public function testClaimingTwiceFailsRatherThanSilentlyProducingAnEmptyObject(): void
    {
        $staged = $this->store->stage($this->upload('cours.pdf', 'body'), 12);
        $this->store->claim($staged, 'library/', 'abc.pdf');

        self::assertFalse($this->store->exists($staged));

        $this->expectException(\League\Flysystem\UnableToCopyFile::class);
        $this->store->claim($staged, 'library/', 'def.pdf');
    }

    public function testAStagedObjectIsScheduledForRemovalTheMomentItIsWritten(): void
    {
        $this->store->stage($this->upload('cours.pdf', 'body'), 12);

        // The fuse: an object exists for no longer than the `staged` window unless a form claims
        // it, whatever happens to the response the browser was waiting for.
        self::assertSame([StagedUploadStore::ORIGIN], $this->scheduled);
    }

    public function testClaimingDefusesTheScheduledRemoval(): void
    {
        $staged = $this->store->stage($this->upload('cours.pdf', 'body'), 12);
        $this->store->claim($staged, 'library/', 'abc.pdf');

        self::assertSame([$staged->key], $this->cancelled);
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'staged-src-');
        file_put_contents($path, $contents);

        // test: true, or UploadedFile refuses a file that did not arrive through PHP's upload
        // handling - which is the whole of what a unit test can offer it.
        return new UploadedFile($path, $name, null, null, true);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        /** @var \SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
