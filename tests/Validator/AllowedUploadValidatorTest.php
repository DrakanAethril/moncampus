<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Service\StagedUpload;
use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The constraint the thirteen upload fields carry, driven through a real validator with real files
 * on disk - the point being that the *name* is now read, which no Assert\File ever did.
 */
class AllowedUploadValidatorTest extends TestCase
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

    public function testAGenuineFilePasses(): void
    {
        $file = $this->upload('rapport.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");

        self::assertSame([], $this->violations($file, new AllowedUpload(UploadPolicy::documents())));
    }

    public function testAFileWhoseBytesLieAboutItsNameIsRefused(): void
    {
        // The hole this closes: every existing field checked the guessed MIME type only, so a file
        // sniffing as text/plain was accepted and stored as notes.bat.
        $file = $this->upload('notes.bat', "echo hello\n");

        self::assertSame(
            [UploadPolicy::VIOLATION_FORBIDDEN],
            $this->violations($file, new AllowedUpload(UploadPolicy::documents())),
        );
    }

    public function testAnExtensionOutsideTheNarrowingIsRefusedEvenThoughThePlatformAllowsIt(): void
    {
        $file = $this->upload('script.py', "print('hi')\n");

        // The platform accepts an inert source file; the documents narrowing does not.
        self::assertSame([], $this->violations($file, new AllowedUpload()));
        self::assertSame(
            [UploadPolicy::VIOLATION_UNSUPPORTED],
            $this->violations($file, new AllowedUpload(UploadPolicy::documents())),
        );
    }

    public function testAGenuineCsvIsAcceptedDespiteSniffingAsPlainText(): void
    {
        // The trap already recorded for this repository: Assert\File(extensions: 'csv') rejects a
        // real CSV, which is guessed as text/plain. The policy's map declares that pairing.
        $file = $this->upload('notes.csv', "nom;note\nDurand;14\n");

        self::assertSame([], $this->violations($file, new AllowedUpload(UploadPolicy::spreadsheets())));
    }

    public function testAnArchiveOnlyExtensionIsRefusedAsABareFile(): void
    {
        $file = $this->upload('page.html', "<!doctype html><p>hello</p>\n");

        self::assertSame(
            [UploadPolicy::VIOLATION_ARCHIVE_ONLY],
            $this->violations($file, new AllowedUpload()),
        );
    }

    public function testAFileWithNoExtensionIsRefused(): void
    {
        $file = $this->upload('README', "hello\n");

        self::assertSame([UploadPolicy::VIOLATION_NO_EXTENSION], $this->violations($file, new AllowedUpload()));
    }

    public function testSizeIsStillEnforcedFromThePolicy(): void
    {
        $file = $this->upload('gros.txt', str_repeat('a', 2048));
        $tiny = new AllowedUpload(UploadPolicy::platform()->withMaxSize('1k'));

        // Symfony's own File constraint raises it, which is exactly why this delegates rather than
        // re-implements - one constraint at the call site, its message handling unchanged.
        self::assertNotSame([], $this->violations($file, $tiny));
    }

    public function testAStagedUploadIsCheckedAgainstTheSamePolicy(): void
    {
        $staged = new StagedUpload('token', 'staged/abc.pdf', 'rapport.pdf', 'application/pdf', 1024);

        self::assertSame([], $this->violations($staged, new AllowedUpload(UploadPolicy::documents())));
        self::assertSame(
            [UploadPolicy::VIOLATION_UNSUPPORTED],
            $this->violations($staged, new AllowedUpload(UploadPolicy::images())),
        );
    }

    public function testALibraryFileIsValidatedRatherThanCrashing(): void
    {
        // The shape that used to reach here unhandled: a file picked from the bibliothèque de
        // fichiers is the node itself, and the validator answered it with an UnexpectedTypeException
        // - a 500 on every form offering the library tab.
        $node = $this->libraryFile('rapport.pdf', 'application/pdf', 1024);

        self::assertSame([], $this->violations($node, new AllowedUpload(UploadPolicy::documents())));
    }

    public function testALibraryFileOutsideTheFieldNarrowingIsRefused(): void
    {
        $node = $this->libraryFile('rapport.pdf', 'application/pdf', 1024);

        // A link is not an exemption: an images-only field refuses a linked PDF exactly as it
        // refuses an uploaded one.
        self::assertSame(
            [UploadPolicy::VIOLATION_UNSUPPORTED],
            $this->violations($node, new AllowedUpload(UploadPolicy::images())),
        );
    }

    public function testALibraryFileIsJudgedOnItsUploadedNameNotOnItsDisplayName(): void
    {
        // A library file is renameable to anything - the display name is a label, not a filename -
        // so the extension the policy reads is the one it arrived with.
        $node = $this->libraryFile('rapport.pdf', 'application/pdf', 1024)->setName('Cours de réseau');

        self::assertSame([], $this->violations($node, new AllowedUpload(UploadPolicy::documents())));
    }

    public function testALibraryFileOverTheFieldCeilingIsRefused(): void
    {
        $node = $this->libraryFile('film.mp4', 'video/mp4', 50 * 1024 * 1024);

        self::assertSame(
            ['uploadPolicyTooLargeMessage'],
            $this->violations($node, new AllowedUpload(UploadPolicy::platform()->withMaxSize('20M'))),
        );
    }

    public function testALibraryFileWithNoRecordedTypeIsLeftToTheExtensionRules(): void
    {
        $node = $this->libraryFile('rapport.pdf', null, 1024);

        self::assertSame([], $this->violations($node, new AllowedUpload(UploadPolicy::documents())));
    }

    public function testNullIsLeftToNotBlankAndNotNull(): void
    {
        self::assertSame([], $this->violations(null, new AllowedUpload()));
    }

    /** @return list<string> the violation message templates raised, in order */
    private function violations(mixed $value, AllowedUpload $constraint): array
    {
        return array_map(
            static fn (mixed $violation): string => (string) $violation->getMessageTemplate(),
            iterator_to_array($this->validator()->validate($value, $constraint)),
        );
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidator();
    }

    /** A library file as the picker submits it: the node itself, resolved from its `lib:48` token. */
    private function libraryFile(string $originalName, ?string $mimeType, int $sizeBytes): FileLibraryNode
    {
        return (new FileLibraryNode(new User('tharaud'), FileLibraryNodeType::File, $originalName))
            ->setOriginalName($originalName)
            ->setMimeType($mimeType)
            ->setSizeBytes($sizeBytes)
            ->setStorageKey('file-library/'.$originalName);
    }

    private function upload(string $clientName, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');

        if (false === $path) {
            self::fail('Could not create a temporary file.');
        }

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        // test: true, so the file is treated as a real upload without having gone through PHP's
        // upload machinery.
        return new UploadedFile($path, $clientName, null, null, true);
    }
}
