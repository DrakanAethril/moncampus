<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VideoUploadValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What the platform accepts as a course video.
 *
 * The decision taken for the whole 5A chantier is that there is NO transcoding: MP4/H.264 is
 * accepted and said so, rather than pretending to be a video platform. This validator is where that
 * sentence becomes true - the screen's `accept="video/mp4"` filters a file dialog and nothing else.
 */
class VideoUploadValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/video-upload-validator-'.uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAnMp4Passes(): void
    {
        self::assertNull((new VideoUploadValidator())->validate($this->mp4('cours.mp4')));
    }

    public function testAFileThatIsNotAVideoIsRefused(): void
    {
        $file = $this->file('cours.mp4', "Je ne suis pas une vidéo, quoi qu'en dise mon nom.\n");

        self::assertSame('videoUploadNotMp4Error', (new VideoUploadValidator())->validate($file));
    }

    /**
     * The refusal names MP4 rather than "video": a teacher handing over a MKV or a MOV has to be
     * told what to convert to, and "format non supporté" leaves them nowhere to go.
     */
    public function testAnotherVideoContainerIsRefusedAsWell(): void
    {
        // A WebM file, byte for byte what a browser recorder produces - a real video, still not the
        // one format this app promises to play everywhere.
        $file = $this->file('cours.webm', "\x1a\x45\xdf\xa3".str_repeat("\x00", 64));

        self::assertSame('videoUploadNotMp4Error', (new VideoUploadValidator())->validate($file));
    }

    public function testTheContentDecidesNotTheDeclaredType(): void
    {
        $file = $this->file('cours.mp4', "Toujours pas une vidéo.\n", 'video/mp4');

        self::assertSame('videoUploadNotMp4Error', (new VideoUploadValidator())->validate($file));
    }

    public function testAVideoOverTheCapIsRefused(): void
    {
        $file = $this->mp4('gros.mp4', VideoUploadValidator::MAX_BYTES + 1);

        self::assertSame('videoUploadTooLargeError', (new VideoUploadValidator())->validate($file));
    }

    public function testNothingUploadedIsRefusedRatherThanIgnored(): void
    {
        // Unlike a PDF attachment, which may legitimately be absent on a resend, this validator only
        // ever runs on the upload endpoint itself: no file there means the transfer failed.
        self::assertSame('videoUploadFailedError', (new VideoUploadValidator())->validate(null));
    }

    /**
     * The cap must stay reachable: PHP refuses an upload over upload_max_filesize before Symfony
     * ever sees it, and the file then lands invalid and sizeless - a "too large" that would read as
     * "not an MP4" if the order of the checks were wrong.
     */
    public function testAnUploadCutShortByPhpIsReportedAsTooLarge(): void
    {
        $path = $this->directory.'/tronque.mp4';
        file_put_contents($path, '');
        $file = new UploadedFile($path, 'tronque.mp4', 'video/mp4', \UPLOAD_ERR_INI_SIZE, true);

        self::assertSame('videoUploadTooLargeError', (new VideoUploadValidator())->validate($file));
    }

    /**
     * A minimal but genuine MP4: the ftyp box is what fileinfo reads to answer video/mp4.
     *
     * An oversized one is grown with ftruncate rather than with padding: the cap is 200 MB, and
     * building that as a PHP string exhausts the test process's memory. A sparse file weighs
     * nothing and filesize() still answers the full length, which is all the validator reads.
     */
    private function mp4(string $name, int $size = 0): UploadedFile
    {
        $header = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $path = $this->directory.'/'.$name;

        $handle = fopen($path, 'w') ?: throw new \RuntimeException(\sprintf('Could not write "%s".', $path));
        fwrite($handle, $header);
        if ($size > \strlen($header)) {
            ftruncate($handle, $size);
        }
        fclose($handle);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function file(string $name, string $content, ?string $declaredMimeType = null): UploadedFile
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $content);

        // test: true, since nothing here came through a real HTTP upload.
        return new UploadedFile($path, $name, $declaredMimeType, null, true);
    }
}
