<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Form\FileUploadDefaults;
use App\Service\UploadPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The platform upload rule (design/validated/upload-policy.md).
 *
 * **One platform rule. Every upload field may narrow it, and may never step outside it.** That is a
 * containment invariant rather than a menu of parallel lists, and the difference is the whole point:
 * parallel per-feature profiles would leave nothing to stop the seventh one from admitting an
 * extension the platform never sanctioned. A narrowing cannot, by construction - and
 * testNarrowingsStayInsideThePlatformList() below is what keeps that true a year from now, because
 * conventions rot and tests do not.
 */
class UploadPolicyTest extends TestCase
{
    // --- The ceiling and what may narrow it ------------------------------------------------

    public function testAFieldNarrowsThePlatformListRatherThanEnumeratingItsOwn(): void
    {
        $pdfOnly = UploadPolicy::platform()->restrictTo('pdf');

        self::assertSame(['pdf'], $pdfOnly->extensions());
        self::assertTrue($pdfOnly->accepts('syllabus.pdf', 'application/pdf'));
        self::assertFalse($pdfOnly->accepts('notes.txt', 'text/plain'));
    }

    public function testNamingAnExtensionOutsideThePlatformListThrows(): void
    {
        // The line between a convention and a rule: a field cannot invent an extension.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dwg/');

        UploadPolicy::platform()->restrictTo('pdf', 'dwg');
    }

    public function testANarrowingCannotReAdmitADeniedOrArchiveOnlyExtension(): void
    {
        // Both are properties of the platform rule, never of a narrowing - neither is in the
        // platform list at all, so naming one is the same error as inventing an extension.
        $refused = [];

        foreach (['exe', 'svg'] as $extension) {
            try {
                UploadPolicy::platform()->restrictTo($extension);
            } catch (\InvalidArgumentException) {
                $refused[] = $extension;
            }
        }

        self::assertSame(['exe', 'svg'], $refused);
    }

    public function testANarrowingOfANarrowingStaysInsideTheFirstOne(): void
    {
        $images = UploadPolicy::platform()->restrictTo('jpg', 'jpeg', 'png', 'webp');

        self::assertSame(['jpg', 'png'], $images->restrictTo('jpg', 'png')->extensions());

        $this->expectException(\InvalidArgumentException::class);
        $images->restrictTo('pdf');
    }

    public function testTheDefaultSizeIsNotTheCeiling(): void
    {
        // Conflating the two is precisely how a field would end up "escaping" the rule to serve a
        // legitimate need - the media narrowing keeps the full ceiling, everything else gets the
        // default.
        self::assertSame(FileUploadDefaults::MAX_SIZE, UploadPolicy::platform()->maxSize());
        self::assertSame(UploadPolicy::PLATFORM_MAX_SIZE, UploadPolicy::media()->maxSize());
        // And the default really is below the ceiling rather than equal to it - the day somebody
        // raises one of the two constants, this is what says the pair still means two things.
        self::assertNotSame(UploadPolicy::platform()->maxSize(), UploadPolicy::media()->maxSize());
    }

    public function testNoFieldMayExceedThePlatformCeiling(): void
    {
        self::assertSame('50M', UploadPolicy::platform()->withMaxSize('50M')->maxSize());

        $this->expectException(\InvalidArgumentException::class);
        UploadPolicy::platform()->withMaxSize('500M');
    }

    // --- The three structural rules --------------------------------------------------------

    public function testTheExtensionIsCrossCheckedAgainstTheSniffedType(): void
    {
        $platform = UploadPolicy::platform();

        // Including the wrong-but-real ones: a genuine CSV sniffs as text/plain, and refusing it is
        // exactly the Assert\File(extensions:) trap already recorded for this repository.
        self::assertTrue($platform->accepts('notes.csv', 'text/plain'));
        self::assertTrue($platform->accepts('capture.pcap', 'application/octet-stream'));
        self::assertTrue($platform->accepts('classeur.xlsx', 'application/zip'));

        // A script wearing a picture's name.
        self::assertFalse($platform->accepts('photo.png', 'text/x-shellscript'));
    }

    public function testTheDenylistAppliesToEveryExtensionSegment(): void
    {
        $platform = UploadPolicy::platform();

        // Windows hides known extensions, and the last segment is what decides the served
        // Content-Type - so both orders are refused.
        self::assertFalse($platform->accepts('report.pdf.exe', 'application/x-dosexec'));
        self::assertFalse($platform->accepts('report.exe.pdf', 'application/pdf'));
        self::assertFalse($platform->accepts('budget.xlsm', 'application/zip'));
    }

    public function testAFileWithNoExtensionIsRefused(): void
    {
        self::assertFalse(UploadPolicy::platform()->accepts('README', 'text/plain'));
        self::assertFalse(UploadPolicy::platform()->accepts('', 'text/plain'));
    }

    public function testTheNameIsLowercasedAndNormalisedBeforeAnythingElse(): void
    {
        $platform = UploadPolicy::platform();

        self::assertTrue($platform->accepts('RAPPORT.PDF', 'application/pdf'));
        self::assertFalse($platform->accepts('setup.EXE', 'application/x-dosexec'));
        // macOS uploads arrive with decomposed accents; the name must fold the same either way.
        self::assertTrue($platform->accepts("Re\u{0301}sume\u{0301}.pdf", 'application/pdf'));
    }

    public function testTheArchiveOnlySetIsRefusedAsABareFileAndCarriedInsideAZip(): void
    {
        $platform = UploadPolicy::platform();

        // Not dangerous by nature but by how they open - inline on the CDN domain, or through the
        // Windows Script Host. Inside a .zip they are inert until somebody extracts them.
        foreach (['page.html', 'logo.svg', 'script.js', 'deploy.sh', 'archive.mhtml'] as $name) {
            self::assertFalse($platform->accepts($name, 'text/plain'), $name);
        }

        self::assertTrue($platform->accepts('cours-web.zip', 'application/zip'));
    }

    public function testPhpStaysAllowedOnPurpose(): void
    {
        // Object storage behind a CDN executes nothing, and it is the single most common file type
        // of a BTS SIO course.
        self::assertTrue(UploadPolicy::platform()->accepts('index.php', 'text/x-php'));
        self::assertTrue(UploadPolicy::platform()->accepts('index.php', 'text/plain'));
    }

    public function testAnUnknownSniffedTypeLeavesTheExtensionRuleAlone(): void
    {
        // fileinfo occasionally has nothing to say. The cross-check exists to catch a lie, and
        // there is nothing here to compare against - but the denylist still decides.
        self::assertTrue(UploadPolicy::platform()->accepts('notes.txt', null));
        self::assertFalse(UploadPolicy::platform()->accepts('setup.exe', null));
    }

    // --- What the refusal says -------------------------------------------------------------

    public function testEachRefusalNamesItsOwnReason(): void
    {
        $platform = UploadPolicy::platform();

        self::assertSame(UploadPolicy::VIOLATION_NO_EXTENSION, $platform->refusalReason('README', 'text/plain'));
        self::assertSame(UploadPolicy::VIOLATION_FORBIDDEN, $platform->refusalReason('setup.exe', null));
        self::assertSame(UploadPolicy::VIOLATION_ARCHIVE_ONLY, $platform->refusalReason('page.html', 'text/html'));
        self::assertSame(UploadPolicy::VIOLATION_UNSUPPORTED, $platform->refusalReason('plan.dwg', null));
        self::assertSame(UploadPolicy::VIOLATION_MISMATCH, $platform->refusalReason('photo.png', 'text/x-shellscript'));
        self::assertNull($platform->refusalReason('rapport.pdf', 'application/pdf'));
    }

    // --- Inline versus attachment ----------------------------------------------------------

    public function testOnlyImagesAndPdfAreServedInline(): void
    {
        // The highest-value measure of the whole policy, and it is not the list: everything else is
        // served Content-Disposition: attachment, which neutralises the entire "dangerous because
        // of how it opens" family regardless of what the allowlist says.
        self::assertTrue(UploadPolicy::servesInline('rapport.pdf'));
        self::assertTrue(UploadPolicy::servesInline('photo.JPG'));
        self::assertTrue(UploadPolicy::servesInline('schema.png'));
        self::assertFalse(UploadPolicy::servesInline('cours.zip'));
        self::assertFalse(UploadPolicy::servesInline('notes.txt'));
        self::assertFalse(UploadPolicy::servesInline('README'));
    }

    // --- The containment invariant itself --------------------------------------------------

    public function testNarrowingsStayInsideThePlatformList(): void
    {
        $platform = UploadPolicy::platform()->extensions();

        foreach (UploadPolicy::narrowings() as $name => $policy) {
            self::assertNotSame([], $policy->extensions(), sprintf('narrowing "%s" accepts nothing', $name));

            foreach ($policy->extensions() as $extension) {
                self::assertContains($extension, $platform, sprintf('narrowing "%s" steps outside the platform list with ".%s"', $name, $extension));
            }
        }
    }

    public function testTheDocumentsNarrowingStillAcceptsExactlyWhatTheTwelveMimeListDid(): void
    {
        // Migrating the five existing forms onto this narrowing is behaviour-preserving, which is
        // what makes it safe to do in one commit. These are the twelve MIME types those forms
        // carried, copy-pasted, and every one of them must still pass.
        $documents = UploadPolicy::documents();

        $cases = [
            ['rapport.pdf', 'application/pdf'],
            ['photo.jpg', 'image/jpeg'],
            ['photo.png', 'image/png'],
            ['photo.webp', 'image/webp'],
            ['note.doc', 'application/msword'],
            ['note.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['diapo.ppt', 'application/vnd.ms-powerpoint'],
            ['diapo.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            ['tableur.xls', 'application/vnd.ms-excel'],
            ['tableur.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['notes.txt', 'text/plain'],
            ['dossier.zip', 'application/zip'],
        ];

        foreach ($cases as [$name, $mime]) {
            self::assertTrue($documents->accepts($name, $mime), $name.' / '.$mime);
        }
    }
}
