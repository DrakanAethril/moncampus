<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlineCourse;

use App\Service\OnlineCourse\OnlineCourseBundleReader;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\WikiArchiveSafety;
use PHPUnit\Framework\TestCase;

/**
 * What the archive of an interactive course may hold (design/validated/cours-en-ligne.md, §6). The
 * reader is the whole gate: what it returns is unpacked into the bucket and served as a web page,
 * so every refusal here is a file that never becomes one.
 */
class OnlineCourseBundleReaderTest extends TestCase
{
    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            @unlink($archive);
        }
    }

    public function testAnArchiveWithItsIndexAtTheRootIsRead(): void
    {
        $bundle = $this->read(['index.html' => '<h1>Cours</h1>', 'assets/app.js' => 'x', 'assets/style.css' => 'y']);

        self::assertSame(['assets/app.js', 'assets/style.css', 'index.html'], $bundle->paths());
        self::assertSame(16, $bundle->totalBytes);
    }

    public function testTheSingleWrappingFolderOfAFinderArchiveIsRemoved(): void
    {
        $bundle = $this->read([
            'jointures-sql/index.html' => '<h1>Cours</h1>',
            'jointures-sql/assets/app.js' => 'x',
            'jointures-sql/.DS_Store' => '',
            '__MACOSX/jointures-sql/._index.html' => 'litter',
        ]);

        self::assertSame(['assets/app.js', 'index.html'], $bundle->paths());
        self::assertSame('jointures-sql/index.html', $bundle->files[1]['entry']);
    }

    public function testAnIndexAtTheRootWinsOverAFolderThatLooksLikeAWrapper(): void
    {
        $bundle = $this->read(['index.html' => 'a', 'cours/page.html' => 'b']);

        self::assertSame(['cours/page.html', 'index.html'], $bundle->paths());
    }

    public function testAnArchiveWithoutAnIndexIsRefused(): void
    {
        $this->assertRefused(['assets/app.js' => 'x'], 'onlineCourseBundleNoIndexMessage');
        $this->assertRefused(['a/index.html' => 'x', 'b/index.html' => 'y'], 'onlineCourseBundleNoIndexMessage');
        $this->assertRefused(['.DS_Store' => ''], 'onlineCourseBundleNoIndexMessage');
    }

    public function testOneFileThatIsNotAWebFileRefusesTheWholeArchiveAndNamesIt(): void
    {
        try {
            $this->read(['index.html' => 'a', 'evil.php' => '<?php']);
            self::fail('A PHP file went through.');
        } catch (OnlineCourseMaterialRefused $refused) {
            self::assertSame('onlineCourseBundleForbiddenFileMessage', $refused->getMessage());
            self::assertSame(['%file%' => 'evil.php'], $refused->parameters);
        }

        $this->assertRefused(['index.html' => 'a', 'setup.exe' => 'MZ'], 'onlineCourseBundleForbiddenFileMessage');
        $this->assertRefused(['index.html' => 'a', 'inner.zip' => 'PK'], 'onlineCourseBundleForbiddenFileMessage');
        $this->assertRefused(['index.html' => 'a', 'no-extension' => 'x'], 'onlineCourseBundleForbiddenFileMessage');
        $this->assertRefused(['index.html' => 'a', '.htaccess' => 'x'], 'onlineCourseBundleForbiddenFileMessage');
    }

    public function testAPathThatLeavesTheFolderIsRefused(): void
    {
        $this->assertRefused(['index.html' => 'a', '../outside.html' => 'b'], 'onlineCourseBundleUnsafePathMessage');
        $this->assertRefused(['index.html' => 'a', '/etc/page.html' => 'b'], 'onlineCourseBundleUnsafePathMessage');
        $this->assertRefused(['index.html' => 'a', 'a\\b.html' => 'b'], 'onlineCourseBundleUnsafePathMessage');
    }

    public function testTooManyFilesAreRefused(): void
    {
        $files = ['index.html' => 'a'];
        for ($i = 0; $i < OnlineCourseBundleReader::MAX_FILES; ++$i) {
            $files['p/'.$i.'.txt'] = '';
        }

        $this->assertRefused($files, 'onlineCourseBundleTooManyFilesMessage');
    }

    public function testSomethingThatIsNotAnArchiveIsRefused(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bundle-');
        $this->archives[] = $path;
        file_put_contents($path, 'not a zip at all');

        $this->expectExceptionMessage('onlineCourseBundleNotAnArchiveMessage');
        (new OnlineCourseBundleReader(new WikiArchiveSafety()))->read($path);
    }

    public function testEveryFileIsServedUnderATypeFromTheList(): void
    {
        self::assertSame('text/html; charset=utf-8', OnlineCourseBundleReader::contentTypeOf('index.HTML'));
        self::assertSame('text/javascript; charset=utf-8', OnlineCourseBundleReader::contentTypeOf('a/b/app.mjs'));
        self::assertSame('font/woff2', OnlineCourseBundleReader::contentTypeOf('f.woff2'));
        self::assertNull(OnlineCourseBundleReader::contentTypeOf('script.php'));
        self::assertNull(OnlineCourseBundleReader::contentTypeOf('Makefile'));
    }

    /**
     * @param array<string, string> $files
     */
    private function assertRefused(array $files, string $messageKey): void
    {
        try {
            $this->read($files);
            self::fail(\sprintf('The archive was accepted; expected %s.', $messageKey));
        } catch (OnlineCourseMaterialRefused $refused) {
            self::assertSame($messageKey, $refused->getMessage());
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function read(array $files): \App\Service\OnlineCourse\OnlineCourseBundle
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bundle-');
        $this->archives[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return (new OnlineCourseBundleReader(new WikiArchiveSafety()))->read($path);
    }
}
